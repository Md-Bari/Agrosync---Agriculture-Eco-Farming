<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AgroSyncController extends Controller
{
    private const OTP_EXPIRY_MINUTES = 10;
    private const SELF_REGISTERABLE_ROLES = ['farmer', 'officer', 'vendor', 'company'];
    private const ORDER_STATUSES = ['pending', 'confirmed', 'accepted', 'dispatched', 'delivered', 'completed', 'cancelled', 'returned'];
    /** Allowed order status transitions per role: current status => next statuses. */
    private const ORDER_TRANSITIONS = [
        'vendor' => [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['dispatched', 'cancelled'],
            'accepted' => ['dispatched', 'cancelled'],
            'dispatched' => ['delivered'],
        ],
        'farmer' => [
            'pending' => ['cancelled'],
            'confirmed' => ['delivered'],
            'accepted' => ['delivered'],
            'dispatched' => ['delivered'],
            'delivered' => ['completed', 'returned'],
        ],
    ];
    private const ORDER_PAYMENT_GATEWAYS = ['cod', 'stripe', 'bkash', 'nagad'];
    private const DEAL_PAYMENT_GATEWAYS = ['cod', 'stripe', 'bkash', 'nagad', 'sslcommerz'];
    private const MAX_PHOTO_BYTES = 2_000_000;
    private const MAX_PHOTOS = 6;
    private ?bool $cropDealPaymentColumnsAvailable = null;
    private ?bool $orderSettlementColumnsAvailable = null;

    /** @var array<string, ?object> Per-request memo for repeated single-row lookups in list mappers. */
    private array $rowCache = [];

    private function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $data], $status);
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    private function forbidden(): JsonResponse
    {
        return $this->fail('You do not have permission to do this.', 403);
    }

    private function cachedRow(string $table, string $column, mixed $value): ?object
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = "{$table}|{$column}|{$value}";
        if (! array_key_exists($key, $this->rowCache)) {
            $this->rowCache[$key] = DB::table($table)->where($column, $value)->first();
        }

        return $this->rowCache[$key];
    }

    private function actor(Request $request): object
    {
        return $request->attributes->get('authUser');
    }

    private function isAdmin(Request $request): bool
    {
        return $this->actor($request)->role === 'admin';
    }

    /** The id of the actor's farmers/officers/vendors/companies row, if any. */
    private function actorRoleId(Request $request): ?string
    {
        $actor = $this->actor($request);
        if (! in_array($actor->role, ['farmer', 'officer', 'vendor', 'company'], true)) {
            return null;
        }

        $row = $this->cachedRow(Str::plural($actor->role), 'user_id', $actor->public_id);

        return $row ? (string) $row->id : null;
    }

    /** Every identifier the frontend may use for the acting user (public id and role-record id). */
    private function actorIds(Request $request): array
    {
        return array_values(array_unique(array_filter([
            (string) $this->actor($request)->public_id,
            $this->actorRoleId($request),
        ])));
    }

    private function ownsId(Request $request, ?string $id): bool
    {
        return $id !== null && $id !== '' && in_array($id, $this->actorIds($request), true);
    }

    private function issueToken(object $user): string
    {
        $plainToken = Str::random(64);

        DB::table('api_tokens')->insert([
            'user_public_id' => $user->public_id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(max(1, (int) config('agrosync.token_ttl_days', 7))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plainToken;
    }

    /** Generate an id with $make() until it is unused in $table.$column. */
    private function uniqueId(string $table, callable $make, string $column = 'id'): string
    {
        do {
            $id = (string) $make();
        } while (DB::table($table)->where($column, $id)->exists());

        return $id;
    }

    /** Accepts http(s) URLs or base64 data:image URLs within the size limit. */
    private function isAllowedImageValue(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_PHOTO_BYTES) {
            return false;
        }

        return (bool) preg_match('#^(https?://\S+|data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+)$#i', $value);
    }

    /** @return array<int, string>|null Sanitized photo list, or null when invalid. */
    private function photoList(mixed $photos): ?array
    {
        if ($photos === null) {
            return [];
        }
        if (! is_array($photos) || count($photos) > self::MAX_PHOTOS) {
            return null;
        }
        foreach ($photos as $photo) {
            if (! $this->isAllowedImageValue($photo)) {
                return null;
            }
        }

        return array_values($photos);
    }

    /** @return array<int, string> Trimmed, non-empty short strings. */
    private function stringList(mixed $value, int $maxItems = 50, int $maxLength = 100): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => mb_substr(trim((string) $item), 0, $maxLength))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->take($maxItems)
            ->values()
            ->all();
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function backendUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function paymentsMockEnabled(): bool
    {
        return (bool) config('agrosync.payments_mock_enabled');
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cropDealPaymentColumnsAvailable(): bool
    {
        if ($this->cropDealPaymentColumnsAvailable !== null) {
            return $this->cropDealPaymentColumnsAvailable;
        }

        $this->cropDealPaymentColumnsAvailable = Schema::hasColumns('crop_deals', ['payment_gateway', 'payment_status', 'payment_ref']);

        return $this->cropDealPaymentColumnsAvailable;
    }

    private function orderSettlementColumnsAvailable(): bool
    {
        if ($this->orderSettlementColumnsAvailable !== null) {
            return $this->orderSettlementColumnsAvailable;
        }

        $this->orderSettlementColumnsAvailable = Schema::hasColumns('orders', [
            'settlement_status',
            'settlement_released_at',
            'settlement_released_by',
        ]);

        return $this->orderSettlementColumnsAvailable;
    }

    private function avatarValue(Request $request): ?string
    {
        $avatar = $request->input('avatar');

        if (! is_string($avatar)) {
            return null;
        }

        $avatar = trim($avatar);

        return $this->isAllowedImageValue($avatar) ? $avatar : null;
    }

    private function normalizedEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    private function weatherEmoji(string $condition): string
    {
        $label = strtolower($condition);

        return match (true) {
            str_contains($label, 'sunny'), str_contains($label, 'clear') => '☀️',
            str_contains($label, 'partly cloudy'), str_contains($label, 'cloudy') => '⛅',
            str_contains($label, 'overcast') => '☁️',
            str_contains($label, 'mist'), str_contains($label, 'fog') => '🌫️',
            str_contains($label, 'thunder') => '⛈️',
            str_contains($label, 'snow'), str_contains($label, 'sleet'), str_contains($label, 'ice') => '❄️',
            str_contains($label, 'drizzle'), str_contains($label, 'rain'), str_contains($label, 'shower') => '🌧️',
            default => '🌤️',
        };
    }

    private function weatherAdvisory(float $rainfall, float $windSpeed, float $tempMax, string $condition): string
    {
        $label = strtolower($condition);

        return match (true) {
            $rainfall >= 25 => 'Heavy rain expected. Avoid pesticide spraying and keep harvested crops covered.',
            $windSpeed >= 35 => 'Strong wind is possible. Secure seedbeds, nets, and lightweight farm structures.',
            $tempMax >= 36 => 'High heat expected. Irrigate early morning or evening and protect workers from midday sun.',
            str_contains($label, 'thunder') => 'Thunderstorm risk. Pause open-field work when lightning is nearby.',
            default => 'Good conditions for routine field work. Keep monitoring humidity before spraying.',
        };
    }

    private function weatherLocation(Request $request): array
    {
        $upazila = trim((string) $request->input('upazila'));
        $district = trim((string) $request->input('district', $request->input('q')));
        $division = trim((string) $request->input('division'));
        $parts = array_values(array_filter([$upazila, $district, $division ?: 'Bangladesh']));

        return [
            'district' => $district !== '' ? $district : 'Dhaka',
            'query' => implode(', ', $parts ?: ['Dhaka', 'Bangladesh']),
        ];
    }

    private function localWeatherForecast(array $location): array
    {
        $district = $location['district'] ?: 'Dhaka';
        $forecast = DB::table('weather_forecasts')
            ->where('district', $district)
            ->where('forecast_date', '>=', now()->toDateString())
            ->orderBy('forecast_date')
            ->limit(7)
            ->get();

        if ($forecast->isEmpty()) {
            $forecast = DB::table('weather_forecasts')
                ->orderBy('forecast_date')
                ->limit(7)
                ->get();
        }

        if ($forecast->isEmpty()) {
            $forecast = collect(range(0, 6))->map(fn (int $offset) => (object) [
                'district' => $district,
                'forecast_date' => now()->addDays($offset)->toDateString(),
                'temp_min' => 22,
                'temp_max' => 31,
                'rainfall' => 5,
                'humidity' => 70,
                'wind_speed' => 10,
                'condition' => 'Clear Sky',
                'icon' => 'sun',
                'advisory' => 'Good conditions for routine field work. Keep monitoring humidity before spraying.',
            ]);
        }

        $days = $forecast->map(fn (object $day) => [
            'district' => $district,
            'date' => (string) $day->forecast_date,
            'tempMin' => (int) $day->temp_min,
            'tempMax' => (int) $day->temp_max,
            'rainfall' => (int) $day->rainfall,
            'humidity' => (int) $day->humidity,
            'windSpeed' => (int) $day->wind_speed,
            'condition' => (string) $day->condition,
            'icon' => (string) ($day->icon ?: $this->weatherEmoji((string) $day->condition)),
            'advisory' => (string) ($day->advisory ?: $this->weatherAdvisory((float) $day->rainfall, (float) $day->wind_speed, (float) $day->temp_max, (string) $day->condition)),
        ])->values();

        $current = $days->first();

        return [
            'location' => $district.', Bangladesh',
            'district' => $district,
            'current' => $current,
            'forecast' => $days,
            'alerts' => $days
                ->filter(fn (array $day) => $day['rainfall'] >= 25 || $day['windSpeed'] >= 35 || $day['tempMax'] >= 36 || str_contains(strtolower($day['condition']), 'thunder'))
                ->map(fn (array $day) => "{$day['condition']} expected on {$day['date']}. {$day['advisory']}")
                ->values()
                ->all(),
        ];
    }

    private function cartIdForFarmer(string $farmerId): string
    {
        $existingCartId = DB::table('carts')->where('farmer_id', $farmerId)->value('id');
        if ($existingCartId) {
            return (string) $existingCartId;
        }

        $cartId = 'cart_'.$farmerId;
        DB::table('carts')->insert([
            'id' => $cartId,
            'farmer_id' => $farmerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cartId;
    }

    private function userByPublicOrRoleId(string $id): ?object
    {
        $user = DB::table('users')->where('public_id', $id)->first();
        if ($user) {
            return $user;
        }

        foreach (['farmers', 'officers', 'vendors', 'companies'] as $table) {
            $roleUserId = DB::table($table)->where('id', $id)->value('user_id');
            if ($roleUserId) {
                return DB::table('users')->where('public_id', $roleUserId)->first();
            }
        }

        return null;
    }

    private function generateAndSendOtp(object $recipient, string $purpose = 'login'): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::OTP_EXPIRY_MINUTES);
        $email = $this->normalizedEmail($recipient->email ?? null);
        $role = $recipient->role ?? null;
        $name = $recipient->name ?? 'User';

        DB::table('auth_otps')
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->delete();

        DB::table('auth_otps')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $recipient->public_id ?? null,
            'email' => $email,
            'role' => $role,
            'purpose' => $purpose,
            'token' => $token,
            'otp_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::raw(
                "Your AgriculMS OTP code is {$code}. This code will expire in ".self::OTP_EXPIRY_MINUTES.' minutes.',
                function ($message) use ($email, $name, $purpose): void {
                    $message
                        ->to($email, $name)
                        ->subject($purpose === 'registration' ? 'Your AgriculMS registration OTP' : 'Your AgriculMS login OTP');
                }
            );
        } catch (Throwable $exception) {
            DB::table('auth_otps')->where('token', $token)->delete();
            report($exception);

            throw $exception;
        }

        return [
            'token' => $token,
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    private function consumeOtpRecord(Request $request, string $purpose = 'login'): ?object
    {
        // The token returned by send-otp is required, so a code can only be guessed
        // against one issued OTP, and that OTP dies after a few wrong attempts.
        $otpToken = trim((string) $request->input('otpToken'));
        $email = $this->normalizedEmail($request->input('email'));
        if ($otpToken === '' || $email === '') {
            return null;
        }

        $record = DB::table('auth_otps')
            ->where('token', $otpToken)
            ->where('email', $email)
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', (int) config('agrosync.otp_max_attempts', 5))
            ->first();

        if (! $record) {
            return null;
        }

        if (! Hash::check((string) $request->input('otp'), $record->otp_hash)) {
            DB::table('auth_otps')->where('id', $record->id)->increment('attempts');

            return null;
        }

        DB::table('auth_otps')->where('id', $record->id)->update([
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);

        return $record;
    }

    private function verifiedRegistrationOtp(Request $request, string $role): ?object
    {
        return DB::table('auth_otps')
            ->where('token', (string) $request->input('registrationOtpToken'))
            ->where('purpose', 'registration')
            ->where('email', $this->normalizedEmail($request->input('email')))
            ->where('role', $role)
            ->whereNotNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    private function user(object $user): array
    {
        $roleRows = [
            'farmer' => null,
            'officer' => null,
            'vendor' => null,
            'company' => null,
        ];
        if (array_key_exists((string) $user->role, $roleRows)) {
            $roleRows[$user->role] = DB::table(Str::plural((string) $user->role))->where('user_id', $user->public_id)->first();
        }
        $roleRecord = $roleRows[$user->role] ?? null;

        $payload = [
            'id' => $roleRecord?->id ?? $user->public_id,
            'userId' => $user->public_id,
            'name' => $user->name,
            'nameBn' => $user->name_bn,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'tenantId' => $user->tenant_id,
            'avatar' => $user->avatar,
            'createdAt' => $user->created_at,
            'password' => '',
            'division' => $user->division,
            'district' => $user->district,
            'upazila' => $user->upazila,
            'designation' => $user->designation,
            'accessLabel' => $user->access_label,
        ];

        if ($farmer = $roleRows['farmer']) {
            $payload += [
                'fid' => $farmer->fid,
                'nidHash' => $farmer->nid_hash,
                'landAcres' => (float) $farmer->land_size,
                'cropTypes' => $this->arrayValue($farmer->crop_types),
                'bkashAccount' => $farmer->bkash_account,
            ];
        }

        if ($officer = $roleRows['officer']) {
            $payload += [
                'officerId' => $officer->officer_id,
                'specialtyTags' => $this->arrayValue($officer->specialty_tags),
                'regionDistricts' => $this->arrayValue($officer->region_districts),
                'maxActiveCases' => (int) $officer->max_active_cases,
                'availabilityStatus' => $officer->availability_status,
                'active' => (bool) $officer->active,
            ];
        }

        if ($vendor = $roleRows['vendor']) {
            $payload += [
                'vendorId' => $vendor->vendor_id,
                'companyName' => $vendor->company_name,
                'deliveryDistricts' => $this->arrayValue($vendor->delivery_districts),
            ];
        }

        if ($company = $roleRows['company']) {
            $payload += [
                'companyId' => $company->company_id,
                'companyName' => $company->company_name,
                'registrationNo' => $company->registration_no,
                'cropInterests' => $this->arrayValue($company->crop_interests),
            ];
        }

        return $payload;
    }

    private function farmer(object $farmer, bool $includeSensitive = false): array
    {
        $user = $this->cachedRow('users', 'public_id', $farmer->user_id);

        $payload = [
            'id' => $farmer->id,
            'fid' => $farmer->fid,
            'name' => $farmer->name_en,
            'nameBn' => $farmer->name_bn,
            'phone' => $farmer->phone,
            'role' => 'farmer',
            'tenantId' => $farmer->tenant_id,
            'avatar' => $user?->avatar,
            'createdAt' => $farmer->created_at,
            'district' => $farmer->district,
            'upazila' => $farmer->upazila,
            'division' => $farmer->division,
            'landAcres' => (float) $farmer->land_size,
            'cropTypes' => $this->arrayValue($farmer->crop_types),
            'cooperativeId' => $farmer->cooperative_id,
            'verified' => (bool) $farmer->verified,
            'blocked' => (bool) $farmer->blocked,
        ];

        if ($includeSensitive) {
            $payload += [
                'email' => $user?->email,
                'nidHash' => $farmer->nid_hash,
                'bkashAccount' => $farmer->bkash_account,
            ];
        }

        return $payload;
    }

    public function users(Request $request): JsonResponse
    {
        $users = DB::table('users')
            ->whereNotNull('public_id')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($user) => $this->user($user));

        return $this->ok($users);
    }

    private function product(object $product): array
    {
        $vendor = $this->cachedRow('vendors', 'id', $product->vendor_id);

        return [
            'id' => $product->id,
            'vendorId' => $product->vendor_id,
            'vendorName' => $vendor?->company_name ?? $product->vendor_id,
            'nameEn' => $product->name_en,
            'nameBn' => $product->name_bn,
            'category' => $product->category,
            'price' => (float) $product->price,
            'unit' => $product->unit,
            'stockQty' => (int) $product->stock_qty,
            'description' => $product->description,
            'manufacturer' => $product->manufacturer,
            'photos' => $this->arrayValue($product->photos),
            'deliveryDistricts' => $this->arrayValue($product->delivery_districts),
            'estimatedDeliveryDays' => (int) $product->estimated_delivery_days,
            'rating' => (float) $product->rating,
            'reviewCount' => (int) $product->review_count,
            'isRecommended' => (bool) $product->is_recommended,
        ];
    }

    private function cropListingProduct(object $listing): array
    {
        $farmer = $this->cachedRow('farmers', 'id', $listing->farmer_id);
        $photos = $this->arrayValue($listing->photos);
        $district = $listing->district ?? $farmer?->district ?? '';
        $upazila = $listing->upazila ?? $farmer?->upazila ?? '';
        $cropType = trim((string) $listing->crop_type);
        $variety = trim((string) $listing->variety);
        $location = implode(', ', array_values(array_filter([$upazila, $district])));

        return [
            'id' => 'listing_'.$listing->id,
            'vendorId' => $listing->farmer_id,
            'vendorName' => $farmer?->name_en ?? 'Farmer',
            'nameEn' => $variety !== '' ? "{$cropType} ({$variety})" : $cropType,
            'nameBn' => $cropType,
            'category' => $listing->product_category ?: 'Fresh Vegetables',
            'price' => (float) $listing->asking_price,
            'unit' => 'kg',
            'stockQty' => (int) $listing->quantity_kg,
            'description' => trim(implode(' ', array_filter([
                $listing->quality_grade ? "{$listing->quality_grade} grade {$cropType}." : null,
                $location !== '' ? "Location: {$location}." : null,
                $listing->harvest_date ? "Harvest date: {$listing->harvest_date}." : null,
            ]))),
            'manufacturer' => $farmer?->name_en ?? 'Farmer',
            'photos' => $photos,
            'deliveryDistricts' => array_values(array_filter([$district])),
            'estimatedDeliveryDays' => 2,
            'rating' => 4.6,
            'reviewCount' => max(12, (int) round(((int) $listing->quantity_kg) / 20)),
            'isRecommended' => false,
        ];
    }

    private function advisoryCase(object $case): array
    {
        $farmer = $this->cachedRow('farmers', 'id', $case->farmer_id);
        $officer = $this->cachedRow('officers', 'id', $case->officer_id);
        $officerUser = $officer ? $this->cachedRow('users', 'public_id', $officer->user_id) : null;

        return [
            'id' => $case->id,
            'farmerId' => $case->farmer_id,
            'farmerName' => $farmer?->name_en ?? 'Farmer',
            'farmerDivision' => $case->farmer_division ?? $farmer?->division ?? '',
            'farmerDistrict' => $case->farmer_district ?? $farmer?->district ?? '',
            'farmerUpazila' => $case->farmer_upazila ?? $farmer?->upazila ?? '',
            'cropType' => $case->crop_type,
            'description' => $case->description,
            'photos' => $this->arrayValue($case->photos),
            'status' => $case->status,
            'priority' => $case->priority ?: 'normal',
            'aiDiagnosis' => $case->ai_diagnosis,
            'aiConfidence' => $case->ai_confidence ? (int) $case->ai_confidence : null,
            'officerId' => $case->officer_id,
            'officerName' => $officerUser?->name,
            'officerResponse' => $case->officer_response,
            'internalNote' => $case->internal_note,
            'overriddenDiagnosis' => $case->overridden_diagnosis,
            'createdAt' => $case->created_at,
            'respondedAt' => $case->responded_at,
            'closedAt' => $case->resolved_at,
        ];
    }

    private function demoAdvisoryCaseIds(): array
    {
        return (array) config('agrosync.demo_advisory_case_ids', []);
    }

    /**
     * Validate request input and return the validated subset, or an error response.
     * (Request::validate() would redirect, since the frontend does not send Accept: application/json.)
     */
    private function validateInput(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        return $validator->validated();
    }

    public function updateUserProfile(Request $request, string $userId): JsonResponse
    {
        $user = $this->userByPublicOrRoleId($userId);

        if (! $user) {
            return $this->fail('User not found.', 404);
        }

        $isAdmin = $this->isAdmin($request);
        if (! $isAdmin && $user->public_id !== $this->actor($request)->public_id) {
            return $this->forbidden();
        }

        $data = $this->validateInput($request, [
            'name' => 'sometimes|required|string|max:120',
            'nameBn' => 'sometimes|nullable|string|max:120',
            'email' => 'sometimes|required|email|max:190',
            'currentPassword' => 'sometimes|nullable|string|max:200',
            'phone' => 'sometimes|nullable|string|max:30',
            'division' => 'sometimes|nullable|string|max:80',
            'district' => 'sometimes|nullable|string|max:80',
            'upazila' => 'sometimes|nullable|string|max:80',
            'designation' => 'sometimes|nullable|string|max:120',
            'accessLabel' => 'sometimes|nullable|string|max:120',
            'landAcres' => 'sometimes|nullable|numeric|min:0|max:100000',
            'bkashAccount' => 'sometimes|nullable|string|max:30',
            'companyName' => 'sometimes|nullable|string|max:150',
            'registrationNo' => 'sometimes|nullable|string|max:80',
            'cropTypes' => 'sometimes|nullable|array|max:50',
            'specialtyTags' => 'sometimes|nullable|array|max:50',
            'regionDistricts' => 'sometimes|nullable|array|max:70',
            'deliveryDistricts' => 'sometimes|nullable|array|max:70',
            'cropInterests' => 'sometimes|nullable|array|max:50',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $has = fn (string $key) => array_key_exists($key, $data);

        if ($has('email')) {
            $nextEmail = $this->normalizedEmail($data['email']);
            if ($nextEmail !== $this->normalizedEmail($user->email)) {
                if (! $isAdmin && ! Hash::check((string) ($data['currentPassword'] ?? ''), $user->password)) {
                    return $this->fail('Enter your current password to change your email address.', 403);
                }
                if (DB::table('users')->whereRaw('lower(email) = ?', [$nextEmail])->where('public_id', '!=', $user->public_id)->exists()) {
                    return $this->fail('This email is already registered for another account.');
                }
            }
        }

        if ($user->role === 'company' && $has('registrationNo') && filled($data['registrationNo'])
            && DB::table('companies')->where('registration_no', $data['registrationNo'])->where('user_id', '!=', $user->public_id)->exists()) {
            return $this->fail('This registration number is already in use.');
        }

        // Only fields present in the request are written, so partial updates keep existing data.
        $userColumns = [
            'name' => 'name',
            'nameBn' => 'name_bn',
            'phone' => 'phone',
            'division' => 'division',
            'district' => 'district',
            'upazila' => 'upazila',
        ];
        if ($isAdmin) {
            $userColumns += ['designation' => 'designation', 'accessLabel' => 'access_label'];
        }

        $now = now();
        $userUpdate = ['updated_at' => $now];
        foreach ($userColumns as $key => $column) {
            if ($has($key)) {
                $userUpdate[$column] = $data[$key];
            }
        }
        if ($has('email')) {
            $userUpdate['email'] = $this->normalizedEmail($data['email']);
        }

        $roleUpdate = [];
        if ($user->role === 'farmer') {
            foreach (['name' => 'name_en', 'nameBn' => 'name_bn', 'division' => 'division', 'district' => 'district', 'upazila' => 'upazila', 'phone' => 'phone', 'bkashAccount' => 'bkash_account', 'landAcres' => 'land_size'] as $key => $column) {
                if ($has($key)) {
                    $roleUpdate[$column] = $data[$key] ?? ($column === 'land_size' ? 0 : ($column === 'district' || $column === 'phone' || $column === 'name_en' ? '' : null));
                }
            }
            if ($has('cropTypes')) {
                $roleUpdate['crop_types'] = json_encode($this->stringList($data['cropTypes']));
            }
        }

        if ($user->role === 'officer') {
            if ($has('specialtyTags')) {
                $roleUpdate['specialty_tags'] = json_encode($this->stringList($data['specialtyTags']));
            }
            if ($has('regionDistricts')) {
                $roleUpdate['region_districts'] = json_encode($this->stringList($data['regionDistricts']));
            }
        }

        if ($user->role === 'vendor') {
            if ($has('companyName') && filled($data['companyName'])) {
                $roleUpdate['company_name'] = $data['companyName'];
            }
            if ($has('deliveryDistricts')) {
                $roleUpdate['delivery_districts'] = json_encode($this->stringList($data['deliveryDistricts']));
            }
        }

        if ($user->role === 'company') {
            if ($has('companyName') && filled($data['companyName'])) {
                $roleUpdate['company_name'] = $data['companyName'];
            }
            if ($has('registrationNo') && filled($data['registrationNo'])) {
                $roleUpdate['registration_no'] = $data['registrationNo'];
            }
            if ($has('cropInterests')) {
                $roleUpdate['crop_interests'] = json_encode($this->stringList($data['cropInterests']));
            }
        }

        DB::transaction(function () use ($user, $userUpdate, $roleUpdate, $now): void {
            DB::table('users')->where('public_id', $user->public_id)->update($userUpdate);

            if ($roleUpdate !== [] && in_array($user->role, ['farmer', 'officer', 'vendor', 'company'], true)) {
                DB::table(Str::plural($user->role))
                    ->where('user_id', $user->public_id)
                    ->update($roleUpdate + ['updated_at' => $now]);
            }
        });

        $updatedUser = DB::table('users')->where('public_id', $user->public_id)->first();

        return $this->ok([
            'success' => true,
            'user' => $this->user($updatedUser),
        ]);
    }

    private function order(object $order): array
    {
        $farmer = $this->cachedRow('farmers', 'id', $order->farmer_id);
        $vendor = $this->cachedRow('vendors', 'id', $order->vendor_id);
        $supportsSettlement = $this->orderSettlementColumnsAvailable();
        $items = DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($item) => [
            'productId' => $item->product_id,
            'productName' => $item->product_name,
            'quantity' => (int) $item->quantity,
            'price' => (float) $item->price,
            'unit' => $item->unit,
        ])->values();

        return [
            'id' => $order->id,
            'farmerId' => $order->farmer_id,
            'farmerName' => $farmer?->name_en ?? 'Farmer',
            'vendorId' => $order->vendor_id,
            'vendorName' => $vendor?->company_name ?? 'Vendor',
            'items' => $items,
            'totalAmount' => (float) $order->total_amount,
            'status' => $order->status,
            'paymentGateway' => $order->payment_gateway,
            'paymentStatus' => $order->payment_status,
            'placedAt' => $order->placed_at,
            'deliveredAt' => $order->delivered_at,
            'estimatedDelivery' => $order->estimated_delivery,
            'settlementStatus' => $supportsSettlement ? ($order->settlement_status ?? 'held') : 'held',
            'settlementReleasedAt' => $supportsSettlement ? ($order->settlement_released_at ?? null) : null,
            'settlementReleasedBy' => $supportsSettlement ? ($order->settlement_released_by ?? null) : null,
        ];
    }

    private function cropListing(object $listing): array
    {
        $farmer = $this->cachedRow('farmers', 'id', $listing->farmer_id);

        return [
            'id' => $listing->id,
            'farmerId' => $listing->farmer_id,
            'farmerName' => $farmer?->name_en ?? 'Farmer',
            'farmerDistrict' => $farmer?->district ?? $listing->district,
            'productCategory' => $listing->product_category,
            'cropType' => $listing->crop_type,
            'variety' => $listing->variety,
            'quantityKg' => (int) $listing->quantity_kg,
            'qualityGrade' => $listing->quality_grade,
            'askingPrice' => (float) $listing->asking_price,
            'harvestDate' => $listing->harvest_date,
            'district' => $listing->district,
            'upazila' => $listing->upazila,
            'photos' => $this->arrayValue($listing->photos),
            'status' => $listing->status,
            'createdAt' => $listing->created_at,
            'matchedCompanyId' => $listing->matched_company_id,
        ];
    }

    public function weatherForecast(Request $request): JsonResponse
    {
        // Location parts are place names: letters (any script), spaces and a little punctuation.
        foreach (['upazila', 'district', 'division', 'q'] as $field) {
            $value = (string) $request->input($field, '');
            if (mb_strlen($value) > 60 || ! preg_match("/^[\\p{L}\\p{M}\\s.,'()-]*$/u", $value)) {
                return $this->fail('Invalid location.');
            }
        }

        $apiKey = (string) config('services.weather.key');
        $location = $this->weatherLocation($request);

        if ($apiKey === '') {
            return $this->ok($this->localWeatherForecast($location));
        }

        try {
            // Cache per location so repeated requests do not spend the paid API quota.
            $fetchedFresh = false;
            $payload = Cache::remember('weather:'.sha1(mb_strtolower($location['query'])), now()->addMinutes(30), function () use ($apiKey, $location, &$fetchedFresh) {
                $response = Http::timeout(15)->get('https://api.weatherapi.com/v1/forecast.json', [
                    'key' => $apiKey,
                    'q' => $location['query'],
                    'days' => 7,
                    'aqi' => 'no',
                    'alerts' => 'yes',
                ]);
                $fetchedFresh = true;

                return $response->successful() ? $response->json() : null;
            });

            if (! is_array($payload)) {
                return $this->fail('Unable to load weather forecast right now.', 502);
            }
            $forecastDays = collect(data_get($payload, 'forecast.forecastday', []));
            $currentTemp = (float) data_get($payload, 'current.temp_c', 0);
            $today = $forecastDays->first();
            $currentCondition = (string) data_get($payload, 'current.condition.text', 'Changing Weather');

            $forecast = $forecastDays->map(function (array $day) use ($location) {
                $condition = (string) data_get($day, 'day.condition.text', 'Changing Weather');
                $rainfall = (float) data_get($day, 'day.totalprecip_mm', 0);
                $windSpeed = (float) data_get($day, 'day.maxwind_kph', 0);
                $tempMax = (float) data_get($day, 'day.maxtemp_c', 0);

                return [
                    'district' => $location['district'],
                    'date' => data_get($day, 'date'),
                    'tempMin' => (int) round((float) data_get($day, 'day.mintemp_c', 0)),
                    'tempMax' => (int) round($tempMax),
                    'rainfall' => (int) round($rainfall),
                    'humidity' => (int) round((float) data_get($day, 'day.avghumidity', 0)),
                    'windSpeed' => (int) round($windSpeed),
                    'condition' => $condition,
                    'icon' => $this->weatherEmoji($condition),
                    'advisory' => $this->weatherAdvisory($rainfall, $windSpeed, $tempMax, $condition),
                ];
            })->values();

            foreach ($fetchedFresh ? $forecast : [] as $day) {
                DB::table('weather_forecasts')->updateOrInsert(
                    ['district' => $day['district'], 'forecast_date' => $day['date']],
                    [
                        'temp_min' => $day['tempMin'],
                        'temp_max' => $day['tempMax'],
                        'rainfall' => $day['rainfall'],
                        'humidity' => $day['humidity'],
                        'wind_speed' => $day['windSpeed'],
                        'condition' => $day['condition'],
                        'icon' => $day['icon'],
                        'advisory' => $day['advisory'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $currentRainfall = (float) data_get($payload, 'current.precip_mm', data_get($today, 'day.totalprecip_mm', 0));
            $currentWind = (float) data_get($payload, 'current.wind_kph', data_get($today, 'day.maxwind_kph', 0));
            $current = [
                'district' => $location['district'],
                'date' => (string) Str::before((string) data_get($payload, 'current.last_updated', now()->toDateTimeString()), ' '),
                'tempMin' => (int) round((float) data_get($today, 'day.mintemp_c', $currentTemp)),
                'tempMax' => (int) round($currentTemp),
                'rainfall' => (int) round($currentRainfall),
                'humidity' => (int) round((float) data_get($payload, 'current.humidity', data_get($today, 'day.avghumidity', 0))),
                'windSpeed' => (int) round($currentWind),
                'condition' => $currentCondition,
                'icon' => $this->weatherEmoji($currentCondition),
                'advisory' => $this->weatherAdvisory($currentRainfall, $currentWind, $currentTemp, $currentCondition),
            ];

            $alerts = collect(data_get($payload, 'alerts.alert', []))
                ->map(function (array $alert) {
                    $headline = trim((string) data_get($alert, 'headline', 'Weather alert issued.'));
                    $instruction = trim((string) data_get($alert, 'instruction', ''));

                    return trim($headline.' '.$instruction);
                })
                ->filter()
                ->values()
                ->all();

            return $this->ok([
                'location' => (string) data_get($payload, 'location.name', $location['district']).', '.(string) data_get($payload, 'location.region', 'Bangladesh'),
                'district' => $location['district'],
                'current' => $current,
                'forecast' => $forecast,
                'alerts' => $alerts,
            ]);
        } catch (Throwable) {
            return $this->ok($this->localWeatherForecast($location));
        }
    }

    public function login(Request $request): JsonResponse
    {
        $user = DB::table('users')
            ->whereRaw('lower(email) = ?', [$this->normalizedEmail($request->input('email'))])
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
            ->first();

        if (! $user) {
            // Spend comparable time on unknown emails so response timing does not reveal accounts.
            Hash::check((string) $request->input('password'), Hash::make(Str::random(16)));

            return $this->fail('Invalid credentials.', 401);
        }

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return $this->fail('Invalid credentials.', 401);
        }

        if ($reason = $this->loginBlockedReason($user)) {
            return $this->fail($reason, 403);
        }

        return $this->ok([
            'success' => true,
            'role' => $user->role,
            'token' => $this->issueToken($user),
            'user' => $this->user($user),
        ]);
    }

    private function loginBlockedReason(object $user): ?string
    {
        if ($user->role === 'farmer' && DB::table('farmers')->where('user_id', $user->public_id)->value('blocked')) {
            return 'This farmer account is blocked.';
        }

        if ($user->role === 'officer' && ! DB::table('officers')->where('user_id', $user->public_id)->value('active')) {
            return 'This officer account is awaiting administrator activation or has been disabled.';
        }

        return null;
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('api_tokens')->where('id', $request->attributes->get('authTokenId'))->delete();

        return $this->ok(['success' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => $this->user($this->actor($request))]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $purpose = (string) $request->input('purpose', 'login');
        if (! in_array($purpose, ['login', 'registration'], true)) {
            return $this->fail('Invalid OTP purpose.');
        }

        $role = (string) $request->input('role', 'farmer');
        $email = $this->normalizedEmail($request->input('email'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->ok(['success' => false, 'message' => 'Enter a valid email address.']);
        }

        if ($purpose === 'registration') {
            if (! in_array($role, self::SELF_REGISTERABLE_ROLES, true)) {
                return $this->ok(['success' => false, 'message' => 'This account type cannot be self-registered.']);
            }

            if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
                return $this->ok(['success' => false, 'message' => 'This email is already registered for another account.']);
            }

            $recipient = (object) [
                'public_id' => null,
                'email' => $email,
                'role' => $role,
                'name' => mb_substr((string) $request->input('name', ucfirst($role)), 0, 120),
            ];
        } else {
            $recipient = DB::table('users')
                ->whereRaw('lower(email) = ?', [$email])
                ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
                ->first();

            if (! $recipient) {
                // Same response as a real send, so this endpoint cannot be used to discover accounts.
                return $this->ok([
                    'success' => true,
                    'otpToken' => Str::random(64),
                    'expiresAt' => now()->addMinutes(self::OTP_EXPIRY_MINUTES)->toIso8601String(),
                    'message' => 'If an account exists for this email, an OTP has been sent to it.',
                ]);
            }
        }

        try {
            $otp = $this->generateAndSendOtp($recipient, $purpose);
        } catch (Throwable) {
            return $this->fail('Unable to send OTP email right now. Please try again.', 500);
        }

        return $this->ok([
            'success' => true,
            'otpToken' => $otp['token'],
            'expiresAt' => $otp['expiresAt'],
            'message' => $purpose === 'login'
                ? 'If an account exists for this email, an OTP has been sent to it.'
                : 'OTP sent successfully to email',
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $purpose = (string) $request->input('purpose', 'login');
        if (! in_array($purpose, ['login', 'registration'], true)) {
            return $this->fail('Invalid OTP purpose.');
        }

        $record = $this->consumeOtpRecord($request, $purpose);

        if (! $record) {
            return $this->ok(['success' => false]);
        }

        if ($purpose === 'registration') {
            return $this->ok([
                'success' => true,
                'verifiedToken' => $record->token,
                'email' => $record->email,
                'role' => $record->role,
            ]);
        }

        $user = DB::table('users')->where('public_id', $record->user_id)->first();
        if (! $user) {
            return $this->ok(['success' => false]);
        }

        if ($reason = $this->loginBlockedReason($user)) {
            return $this->fail($reason, 403);
        }

        return $this->ok([
            'success' => true,
            'user' => $this->user($user),
            'role' => $user->role,
            'token' => $this->issueToken($user),
        ]);
    }

    public function registerRoleUser(Request $request): JsonResponse
    {
        $role = (string) $request->input('role', 'farmer');
        if (! in_array($role, self::SELF_REGISTERABLE_ROLES, true)) {
            return $this->ok(['success' => false, 'message' => 'This account type cannot be self-registered.']);
        }

        $data = $this->validateInput($request, [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|max:30',
            'password' => 'required|string|min:8|max:200',
            'registrationOtpToken' => 'required|string|max:100',
            'nameBn' => 'nullable|string|max:120',
            'division' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:80',
            'upazila' => 'nullable|string|max:80',
            'designation' => 'nullable|string|max:120',
            'tenantId' => 'nullable|string|max:64',
            'fid' => 'nullable|string|max:40',
            'nidHash' => 'nullable|string|max:128',
            'landAcres' => 'nullable|numeric|min:0|max:100000',
            'bkashAccount' => 'nullable|string|max:30',
            'companyName' => 'nullable|string|max:150',
            'registrationNo' => 'nullable|string|max:80',
            'cropTypes' => 'nullable|array|max:50',
            'specialtyTags' => 'nullable|array|max:50',
            'regionDistricts' => 'nullable|array|max:70',
            'deliveryDistricts' => 'nullable|array|max:70',
            'cropInterests' => 'nullable|array|max:50',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $email = $this->normalizedEmail($data['email']);
        if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
            return $this->ok(['success' => false, 'message' => 'This email is already registered for another account.']);
        }

        $verifiedRegistrationOtp = $this->verifiedRegistrationOtp($request, $role);
        if (! $verifiedRegistrationOtp) {
            return $this->ok(['success' => false, 'message' => 'Please verify the registration OTP sent to your email before submitting.']);
        }

        if (filled($data['fid'] ?? null) && DB::table('farmers')->where('fid', $data['fid'])->exists()) {
            return $this->ok(['success' => false, 'message' => 'This farmer ID is already registered.']);
        }
        if (filled($data['registrationNo'] ?? null) && DB::table('companies')->where('registration_no', $data['registrationNo'])->exists()) {
            return $this->ok(['success' => false, 'message' => 'This company registration number is already registered.']);
        }

        $publicId = $this->createRoleAccount($role, $data, $email, $this->avatarValue($request), active: $role !== 'officer');
        DB::table('auth_otps')->where('id', $verifiedRegistrationOtp->id)->delete();

        $created = DB::table('users')->where('public_id', $publicId)->first();

        return $this->ok([
            'success' => true,
            'user' => $this->user($created),
            'message' => $role === 'officer'
                ? 'Officer account created. An administrator must activate it before you can sign in.'
                : 'Account created successfully.',
        ]);
    }

    public function createOfficer(Request $request): JsonResponse
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|max:30',
            'district' => 'nullable|string|max:80',
            'regionDistricts' => 'nullable|array|max:70',
            'specialtyTags' => 'nullable|array|max:50',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $email = $this->normalizedEmail($data['email']);
        if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
            return $this->fail('This email is already registered for another account.');
        }

        // The officer signs in with an email OTP; this random password is never shown to anyone.
        $data['password'] = Str::password(32);
        $data['tenantId'] = $this->actor($request)->tenant_id;
        $publicId = $this->createRoleAccount('officer', $data, $email, null, active: true);

        $this->createAuditLog('officer', 'create', $this->actor($request)->name, "Added officer {$data['name']} ({$publicId}).");

        return $this->ok([
            'success' => true,
            'officerId' => $publicId,
            'user' => $this->user(DB::table('users')->where('public_id', $publicId)->first()),
        ]);
    }

    private function defaultTenantId(?string $requested): string
    {
        if ($requested && DB::table('tenants')->where('id', $requested)->exists()) {
            return $requested;
        }

        return DB::table('tenants')->where('id', 'tenant_001')->exists()
            ? 'tenant_001'
            : (string) (DB::table('tenants')->orderBy('created_at')->value('id') ?? 'tenant_001');
    }

    /** Insert a users row plus its role row. Ids are generated server-side. Returns the public id. */
    private function createRoleAccount(string $role, array $data, string $email, ?string $avatar, bool $active): string
    {
        $now = now();
        $tenantId = $this->defaultTenantId($data['tenantId'] ?? null);
        $prefix = substr($role, 0, 3);
        $publicId = $this->uniqueId('users', fn () => "usr_{$prefix}_".Str::lower(Str::random(10)), 'public_id');
        $accessLabels = [
            'farmer' => 'Registered Farmer',
            'officer' => 'Regional Advisory Officer',
            'vendor' => 'Marketplace Vendor',
            'company' => 'Crop Buyer',
        ];

        DB::transaction(function () use ($role, $data, $email, $avatar, $active, $now, $tenantId, $publicId, $accessLabels): void {
            DB::table('users')->insert([
                'public_id' => $publicId,
                'tenant_id' => $tenantId,
                'role' => $role,
                'name' => $data['name'],
                'name_bn' => $data['nameBn'] ?? null,
                'email' => $email,
                'phone' => $data['phone'],
                'avatar' => $avatar,
                'division' => $data['division'] ?? null,
                'district' => $data['district'] ?? null,
                'upazila' => $data['upazila'] ?? null,
                'designation' => $data['designation'] ?? null,
                'access_label' => $accessLabels[$role] ?? null,
                'password' => Hash::make($data['password']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $common = ['id' => $publicId, 'user_id' => $publicId, 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now];

            if ($role === 'farmer') {
                DB::table('farmers')->insert($common + [
                    'fid' => filled($data['fid'] ?? null)
                        ? $data['fid']
                        : $this->uniqueId('farmers', fn () => 'AGS-'.$now->year.'-'.str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT), 'fid'),
                    'nid_hash' => $data['nidHash'] ?? 'nid_'.Str::random(12),
                    'name_bn' => $data['nameBn'] ?? null,
                    'name_en' => $data['name'],
                    'division' => $data['division'] ?? '',
                    'district' => $data['district'] ?? '',
                    'upazila' => $data['upazila'] ?? '',
                    'land_size' => $data['landAcres'] ?? 0,
                    'crop_types' => json_encode($this->stringList($data['cropTypes'] ?? [])),
                    'phone' => $data['phone'],
                    'bkash_account' => $data['bkashAccount'] ?? $data['phone'],
                ]);
            }

            if ($role === 'officer') {
                $regionDistricts = $this->stringList($data['regionDistricts'] ?? []);
                if ($regionDistricts === [] && filled($data['district'] ?? null)) {
                    $regionDistricts = [$data['district']];
                }

                DB::table('officers')->insert($common + [
                    'officer_id' => $this->uniqueId('officers', fn () => 'OFF-'.strtoupper(Str::random(6)), 'officer_id'),
                    'specialty_tags' => json_encode($this->stringList($data['specialtyTags'] ?? [])),
                    'region_districts' => json_encode($regionDistricts),
                    'active' => $active,
                    'availability_status' => $active ? 'available' : 'offline',
                ]);
            }

            if ($role === 'vendor') {
                DB::table('vendors')->insert($common + [
                    'vendor_id' => $this->uniqueId('vendors', fn () => 'VND-'.strtoupper(Str::random(6)), 'vendor_id'),
                    'company_name' => filled($data['companyName'] ?? null) ? $data['companyName'] : $data['name'],
                    'delivery_districts' => json_encode($this->stringList($data['deliveryDistricts'] ?? [])),
                    'status' => 'approved',
                ]);
            }

            if ($role === 'company') {
                DB::table('companies')->insert($common + [
                    'company_id' => $this->uniqueId('companies', fn () => 'CMP-'.strtoupper(Str::random(6)), 'company_id'),
                    'company_name' => filled($data['companyName'] ?? null) ? $data['companyName'] : $data['name'],
                    'registration_no' => filled($data['registrationNo'] ?? null)
                        ? $data['registrationNo']
                        : $this->uniqueId('companies', fn () => 'COMP-'.$now->year.'-'.strtoupper(Str::random(6)), 'registration_no'),
                    'crop_interests' => json_encode($this->stringList($data['cropInterests'] ?? [])),
                ]);
            }
        });

        return $publicId;
    }

    public function farmers(Request $request): JsonResponse
    {
        $includeSensitive = $this->isAdmin($request);

        $farmers = DB::table('farmers')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%'.strtolower((string) $request->input('q')).'%';
                $query->whereRaw('lower(id) like ? or lower(user_id) like ? or lower(name_en) like ? or lower(fid) like ? or lower(district) like ?', [$q, $q, $q, $q, $q]);
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($farmer) => $this->farmer($farmer, $includeSensitive));

        return $this->ok($farmers);
    }

    public function advisoryCases(Request $request): JsonResponse
    {
        // Farmers only ever see their own cases; officers and admins see all and may filter.
        $isFarmer = $this->actor($request)->role === 'farmer';

        $cases = DB::table('advisory_cases')
            ->when($isFarmer, fn ($query) => $query->whereIn('farmer_id', $this->actorIds($request)))
            ->when(! $isFarmer && $request->filled('farmerId'), fn ($query) => $query->where('farmer_id', $request->input('farmerId')))
            ->when($request->filled('officerId'), function ($query) use ($request) {
                $officerId = trim((string) $request->input('officerId'));
                if ($officerId === '') {
                    return;
                }

                $matchedOfficerIds = DB::table('officers')
                    ->where('officer_id', $officerId)
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();

                $query->where(function ($innerQuery) use ($officerId, $matchedOfficerIds): void {
                    $innerQuery->where('officer_id', $officerId);
                    if (! empty($matchedOfficerIds)) {
                        $innerQuery->orWhereIn('officer_id', $matchedOfficerIds);
                    }
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%'.strtolower(trim((string) $request->input('q'))).'%';
                $farmerIds = DB::table('farmers')
                    ->whereRaw('lower(name_en) like ?', [$search])
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();

                $query->where(function ($innerQuery) use ($search, $farmerIds): void {
                    $innerQuery
                        ->whereRaw('lower(id) like ?', [$search])
                        ->orWhereRaw('lower(crop_type) like ?', [$search])
                        ->orWhereRaw('lower(description) like ?', [$search]);

                    if (! empty($farmerIds)) {
                        $innerQuery->orWhereIn('farmer_id', $farmerIds);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($case) => $this->advisoryCase($case));

        return $this->ok($cases);
    }

    public function submitAdvisory(Request $request): JsonResponse
    {
        if ($request->filled('farmerId') && ! $this->ownsId($request, (string) $request->input('farmerId'))) {
            return $this->forbidden();
        }

        $farmer = DB::table('farmers')->where('user_id', $this->actor($request)->public_id)->first();
        if (! $farmer) {
            return $this->fail('Farmer not found.');
        }

        $data = $this->validateInput($request, [
            'cropType' => 'required|string|max:80',
            'description' => 'required|string|max:5000',
            'priority' => 'nullable|string|max:20',
            'photos' => 'nullable|array',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $photos = $this->photoList($data['photos'] ?? []);
        if ($photos === null) {
            return $this->fail('Attach up to '.self::MAX_PHOTOS.' images (PNG, JPEG, GIF or WebP, max 2 MB each).');
        }

        $priority = strtolower(trim((string) ($data['priority'] ?? ''))) === 'urgent' ? 'urgent' : 'normal';
        $caseId = $this->uniqueId('advisory_cases', fn () => 'ADV-'.now()->year.'-'.str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT));
        $description = trim($data['description']);

        DB::table('advisory_cases')->insert([
            'id' => $caseId,
            'tenant_id' => $farmer->tenant_id,
            'farmer_id' => $farmer->id,
            'farmer_division' => $farmer->division,
            'farmer_district' => $farmer->district,
            'farmer_upazila' => $farmer->upazila,
            'crop_type' => $data['cropType'],
            'description' => $description,
            'photos' => json_encode($photos),
            'status' => 'pending',
            'priority' => $priority,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('advisory_messages')->insert([
            'case_id' => $caseId,
            'sender_id' => $farmer->id,
            'sender_role' => 'farmer',
            'message' => $description,
            'attachments' => json_encode($photos),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createNotification($farmer->id, 'advisory', 'Advisory Submitted', "Your advisory case {$caseId} has been submitted.", ['push', 'email']);
        $this->notifyOfficersForAdvisory($farmer, $caseId, $data['cropType'], $priority);

        return $this->ok(['success' => true, 'caseId' => $caseId]);
    }

    public function respondToCase(Request $request, string $caseId): JsonResponse
    {
        $case = DB::table('advisory_cases')->where('id', $caseId)->first();
        if (! $case) {
            return $this->fail('Advisory case not found.', 404);
        }

        $officer = DB::table('officers')->where('user_id', $this->actor($request)->public_id)->first();
        if (! $officer) {
            return $this->forbidden();
        }

        // A case already assigned to another officer can only be answered by that officer.
        if ($case->officer_id && $case->officer_id !== $officer->id) {
            return $this->fail('This case is assigned to another officer.', 403);
        }

        $responseText = trim((string) $request->input('response'));
        if ($responseText === '') {
            return $this->fail('Response is required.');
        }
        if (mb_strlen($responseText) > 5000) {
            return $this->fail('Response must be 5000 characters or fewer.');
        }

        DB::table('advisory_cases')->where('id', $caseId)->update([
            'status' => 'responded',
            'officer_id' => $officer->id,
            'officer_response' => $responseText,
            'responded_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('advisory_messages')->insert([
            'case_id' => $caseId,
            'sender_id' => $officer->user_id,
            'sender_role' => 'officer',
            'message' => $responseText,
            'attachments' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createNotification(
            (string) $case->farmer_id,
            'advisory',
            'Officer Response Received',
            "An officer has responded to your advisory case {$caseId}.",
            ['push', 'sms'],
        );

        return $this->ok(['success' => true]);
    }

    public function regionalAdvisoryStats(Request $request): JsonResponse
    {
        $regionalCases = DB::table('advisory_cases')
            ->whereNotIn('advisory_cases.id', $this->demoAdvisoryCaseIds())
            ->join('farmers', 'advisory_cases.farmer_id', '=', 'farmers.id')
            ->select(
                'advisory_cases.id',
                'advisory_cases.status',
                DB::raw("COALESCE(NULLIF(advisory_cases.farmer_division, ''), farmers.division) as division"),
                DB::raw("COALESCE(NULLIF(advisory_cases.farmer_district, ''), farmers.district) as district")
            );

        $stats = DB::query()
            ->fromSub($regionalCases, 'regional_cases')
            ->whereNotNull('district')
            ->where('district', '!=', '')
            ->select(
                'division',
                'district',
                DB::raw('COUNT(id) as count'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw("SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded_count"),
                DB::raw("SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved_count")
            )
            ->groupBy('division', 'district')
            ->orderByDesc(DB::raw('COUNT(id)'))
            ->get()
            ->map(fn ($row) => [
                'division' => $row->division,
                'district' => $row->district,
                'total_cases' => $row->count,
                'pending_cases' => $row->pending_count,
                'responded_cases' => $row->responded_count,
                'resolved_cases' => $row->resolved_count,
            ]);

        return $this->ok($stats);
    }

    public function products(Request $request): JsonResponse
    {
        $products = DB::table('products')
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->input('category')))
            ->when($request->filled('vendorId'), fn ($query) => $query->where('vendor_id', $request->input('vendorId')))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($product) => [
                ...$this->product($product),
                '__sortCreatedAt' => $product->created_at,
            ]);

        if (! $request->filled('vendorId') && $request->boolean('includeCropListings', false)) {
            $listingProducts = DB::table('crop_listings')
                ->where('status', 'active')
                ->when($request->filled('category'), fn ($query) => $query->where('product_category', $request->input('category')))
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($listing) => [
                    ...$this->cropListingProduct($listing),
                    '__sortCreatedAt' => $listing->created_at,
                ]);

            $products = $products
                ->concat($listingProducts)
                ->sortByDesc('__sortCreatedAt')
                ->values();
        }

        $products = $products
            ->map(function (array $product) {
                unset($product['__sortCreatedAt']);

                return $product;
            })
            ->values();

        return $this->ok($products);
    }

    private function productRules(bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $opt = $partial ? 'sometimes|nullable' : 'nullable';

        return [
            'nameEn' => "{$req}|string|max:150",
            'nameBn' => "{$opt}|string|max:150",
            'category' => "{$req}|string|max:80",
            'price' => "{$req}|numeric|min:0.01|max:10000000",
            'unit' => "{$req}|string|max:30",
            'stockQty' => "{$opt}|integer|min:0|max:1000000",
            'description' => "{$opt}|string|max:5000",
            'manufacturer' => "{$opt}|string|max:150",
            'photos' => "{$opt}|array",
            'deliveryDistricts' => "{$opt}|array|max:70",
            'estimatedDeliveryDays' => "{$opt}|integer|min:1|max:60",
            'isRecommended' => "{$opt}|boolean",
            'vendorId' => "{$opt}|string|max:64",
        ];
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $this->validateInput($request, $this->productRules(false));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if ($this->isAdmin($request)) {
            $vendor = DB::table('vendors')->where('id', (string) ($data['vendorId'] ?? ''))->first();
        } else {
            if (filled($data['vendorId'] ?? null) && ! $this->ownsId($request, $data['vendorId'])) {
                return $this->forbidden();
            }
            $vendor = DB::table('vendors')->where('user_id', $this->actor($request)->public_id)->first();
        }
        if (! $vendor) {
            return $this->fail('Vendor not found.', 404);
        }

        $photos = $this->photoList($data['photos'] ?? []);
        if ($photos === null) {
            return $this->fail('Attach up to '.self::MAX_PHOTOS.' images (PNG, JPEG, GIF or WebP, max 2 MB each).');
        }

        $id = $this->uniqueId('products', fn () => 'prod_'.Str::lower(Str::random(10)));
        DB::table('products')->insert([
            'id' => $id,
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'name_en' => $data['nameEn'],
            'name_bn' => $data['nameBn'] ?? null,
            'category' => $data['category'],
            'price' => $data['price'],
            'unit' => $data['unit'],
            'stock_qty' => $data['stockQty'] ?? 0,
            'description' => $data['description'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'photos' => json_encode($photos),
            'delivery_districts' => json_encode($this->stringList($data['deliveryDistricts'] ?? [])),
            'estimated_delivery_days' => $data['estimatedDeliveryDays'] ?? 3,
            'rating' => 0,
            'review_count' => 0,
            'is_recommended' => $this->isAdmin($request) && ! empty($data['isRecommended']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(['success' => true, 'productId' => $id]);
    }

    /** The product if the actor may change it (admin, or the owning vendor), otherwise an error response. */
    private function editableProduct(Request $request, string $productId): object
    {
        $product = DB::table('products')->where('id', $productId)->first();
        if (! $product) {
            return $this->fail('Product not found.', 404);
        }

        if (! $this->isAdmin($request) && ! $this->ownsId($request, (string) $product->vendor_id)) {
            return $this->forbidden();
        }

        return $product;
    }

    public function updateProduct(Request $request, string $productId): JsonResponse
    {
        $product = $this->editableProduct($request, $productId);
        if ($product instanceof JsonResponse) {
            return $product;
        }

        $data = $this->validateInput($request, $this->productRules(true));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        // Explicit allow-list: ids, vendor, tenant, rating and review counts are never client-writable.
        $columns = [
            'nameEn' => 'name_en',
            'nameBn' => 'name_bn',
            'category' => 'category',
            'price' => 'price',
            'unit' => 'unit',
            'stockQty' => 'stock_qty',
            'description' => 'description',
            'manufacturer' => 'manufacturer',
            'estimatedDeliveryDays' => 'estimated_delivery_days',
        ];
        $update = [];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $data)) {
                $update[$column] = $data[$key];
            }
        }
        if (array_key_exists('photos', $data)) {
            $photos = $this->photoList($data['photos']);
            if ($photos === null) {
                return $this->fail('Attach up to '.self::MAX_PHOTOS.' images (PNG, JPEG, GIF or WebP, max 2 MB each).');
            }
            $update['photos'] = json_encode($photos);
        }
        if (array_key_exists('deliveryDistricts', $data)) {
            $update['delivery_districts'] = json_encode($this->stringList($data['deliveryDistricts']));
        }
        if (array_key_exists('isRecommended', $data) && $this->isAdmin($request)) {
            $update['is_recommended'] = (bool) $data['isRecommended'];
        }
        $update['updated_at'] = now();

        DB::table('products')->where('id', $product->id)->update($update);

        return $this->ok(['success' => true]);
    }

    public function deleteProduct(Request $request, string $productId): JsonResponse
    {
        $product = $this->editableProduct($request, $productId);
        if ($product instanceof JsonResponse) {
            return $product;
        }

        DB::table('products')->where('id', $product->id)->delete();

        return $this->ok(['success' => true]);
    }

    /** The acting farmer's farmers.id if $farmerId refers to them, otherwise null. */
    private function ownFarmerId(Request $request, string $farmerId): ?string
    {
        return $this->ownsId($request, $farmerId) ? $this->actorRoleId($request) : null;
    }

    public function cart(Request $request, string $farmerId): JsonResponse
    {
        $ownFarmerId = $this->ownFarmerId($request, $farmerId);
        if (! $ownFarmerId) {
            return $this->forbidden();
        }

        $items = DB::table('cart_items')
            ->where('cart_id', $this->cartIdForFarmer($ownFarmerId))
            ->get()
            ->map(function ($item) {
                $product = DB::table('products')->where('id', $item->product_id)->first();

                return $product ? ['product' => $this->product($product), 'quantity' => (int) $item->quantity] : null;
            })
            ->filter()
            ->values();

        return $this->ok($items);
    }

    public function addToCart(Request $request, string $farmerId): JsonResponse
    {
        $ownFarmerId = $this->ownFarmerId($request, $farmerId);
        if (! $ownFarmerId) {
            return $this->forbidden();
        }

        $data = $this->validateInput($request, [
            'productId' => 'required|string|max:64',
            'quantity' => 'nullable|integer|min:1|max:10000',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (! DB::table('products')->where('id', $data['productId'])->exists()) {
            return $this->ok(['success' => false, 'message' => 'Product not found.']);
        }

        $cartId = $this->cartIdForFarmer($ownFarmerId);
        $existing = DB::table('cart_items')->where('cart_id', $cartId)->where('product_id', $data['productId'])->first();

        DB::table('cart_items')->updateOrInsert(
            ['cart_id' => $cartId, 'product_id' => $data['productId']],
            ['quantity' => min(10000, ($existing?->quantity ?? 0) + (int) ($data['quantity'] ?? 1)), 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now()]
        );

        return $this->ok(['success' => true]);
    }

    public function updateCartItem(Request $request, string $farmerId, string $productId): JsonResponse
    {
        $ownFarmerId = $this->ownFarmerId($request, $farmerId);
        if (! $ownFarmerId) {
            return $this->forbidden();
        }

        $data = $this->validateInput($request, ['quantity' => 'required|integer|min:0|max:10000']);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $cartId = $this->cartIdForFarmer($ownFarmerId);
        if ((int) $data['quantity'] === 0) {
            DB::table('cart_items')->where('cart_id', $cartId)->where('product_id', $productId)->delete();
        } else {
            DB::table('cart_items')->where('cart_id', $cartId)->where('product_id', $productId)->update(['quantity' => (int) $data['quantity'], 'updated_at' => now()]);
        }

        return $this->ok(['success' => true]);
    }

    public function clearCart(Request $request, string $farmerId): JsonResponse
    {
        $ownFarmerId = $this->ownFarmerId($request, $farmerId);
        if (! $ownFarmerId) {
            return $this->forbidden();
        }

        DB::table('cart_items')->where('cart_id', $this->cartIdForFarmer($ownFarmerId))->delete();

        return $this->ok(['success' => true]);
    }

    public function orders(Request $request): JsonResponse
    {
        $role = $this->actor($request)->role;
        $actorIds = $this->actorIds($request);

        $orders = DB::table('orders')
            ->when($role === 'farmer', fn ($query) => $query->whereIn('farmer_id', $actorIds))
            ->when($role === 'vendor', fn ($query) => $query->whereIn('vendor_id', $actorIds))
            ->when($role === 'admin' && $request->filled('farmerId'), fn ($query) => $query->where('farmer_id', $request->input('farmerId')))
            ->when($role === 'admin' && $request->filled('vendorId'), fn ($query) => $query->where('vendor_id', $request->input('vendorId')))
            ->orderByDesc('placed_at')
            ->get()
            ->map(fn ($order) => $this->order($order));

        return $this->ok($orders);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        if ($request->filled('farmerId') && ! $this->ownsId($request, (string) $request->input('farmerId'))) {
            return $this->forbidden();
        }

        $farmer = DB::table('farmers')->where('user_id', $this->actor($request)->public_id)->first();
        if (! $farmer) {
            return $this->fail('Farmer not found.', 404);
        }

        $data = $this->validateInput($request, [
            'items' => 'required|array|min:1|max:100',
            'items.*.productId' => 'required|string|max:64',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'paymentGateway' => 'nullable|in:'.implode(',', self::ORDER_PAYMENT_GATEWAYS),
        ]);
        if ($data instanceof JsonResponse) {
            return $data->getStatusCode() === 422 && empty($request->input('items'))
                ? $this->ok(['success' => false, 'message' => 'Cart is empty.'])
                : $data;
        }

        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[$item['productId']] = ($quantities[$item['productId']] ?? 0) + (int) $item['quantity'];
        }

        // Payment is never assumed: every order starts unpaid. COD becomes paid on delivery;
        // Stripe becomes paid only after the gateway confirms; bKash/Nagad await manual verification.
        $paymentGateway = $data['paymentGateway'] ?? 'cod';
        $supportsSettlement = $this->orderSettlementColumnsAvailable();

        $result = DB::transaction(function () use ($farmer, $quantities, $paymentGateway, $supportsSettlement) {
            $products = DB::table('products')->whereIn('id', array_keys($quantities))->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($quantities)) {
                return 'Some cart items are unavailable.';
            }

            $vendorId = $products->first()->vendor_id;
            if ($products->contains(fn ($product) => $product->vendor_id !== $vendorId)) {
                return 'Please checkout products from one vendor at a time.';
            }

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];
                if ((int) $product->stock_qty < $quantity) {
                    return "Only {$product->stock_qty} {$product->unit} of {$product->name_en} left in stock.";
                }
            }

            $orderId = $this->uniqueId('orders', fn () => 'ORD-'.now()->year.'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
            $total = $products->sum(fn ($product) => ((float) $product->price) * $quantities[$product->id]);
            $maxDays = (int) $products->max(fn ($product) => (int) $product->estimated_delivery_days);

            $orderPayload = [
                'id' => $orderId,
                'tenant_id' => $farmer->tenant_id,
                'farmer_id' => $farmer->id,
                'vendor_id' => $vendorId,
                'status' => 'pending',
                'total_amount' => round($total, 2),
                'payment_gateway' => $paymentGateway,
                'payment_status' => 'pending',
                'estimated_delivery' => now()->addDays($maxDays)->toDateString(),
                'placed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($supportsSettlement) {
                $orderPayload['settlement_status'] = 'held';
                $orderPayload['settlement_released_at'] = null;
                $orderPayload['settlement_released_by'] = null;
            }
            DB::table('orders')->insert($orderPayload);

            foreach ($products as $product) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_name' => $product->name_en,
                    'quantity' => $quantities[$product->id],
                    'price' => $product->price,
                    'unit' => $product->unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('products')->where('id', $product->id)->decrement('stock_qty', $quantities[$product->id]);
            }

            DB::table('cart_items')->where('cart_id', $this->cartIdForFarmer((string) $farmer->id))->delete();

            return ['orderId' => $orderId, 'vendorId' => $vendorId];
        });

        if (is_string($result)) {
            return $this->ok(['success' => false, 'message' => $result]);
        }

        $orderId = $result['orderId'];
        $this->createNotification($farmer->id, 'order', 'Order Placed Successfully', "Order {$orderId} has been placed.", ['push', 'sms']);
        $vendorUserId = DB::table('vendors')->where('id', $result['vendorId'])->value('user_id');
        if ($vendorUserId) {
            $this->createNotification(
                (string) $vendorUserId,
                'order',
                'New Order Received',
                "New order {$orderId} has been placed and is waiting for your confirmation.",
                ['push', 'sms'],
            );
        }

        return $this->ok(['success' => true, 'orderId' => $orderId]);
    }

    public function updateOrderStatus(Request $request, string $orderId): JsonResponse
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (! $order) {
            return $this->fail('Order not found.', 404);
        }

        $role = $this->actor($request)->role;
        if ($role === 'vendor' && ! $this->ownsId($request, (string) $order->vendor_id)) {
            return $this->forbidden();
        }
        if ($role === 'farmer' && ! $this->ownsId($request, (string) $order->farmer_id)) {
            return $this->forbidden();
        }

        $nextStatus = (string) $request->input('status');
        if (! in_array($nextStatus, self::ORDER_STATUSES, true)) {
            return $this->fail('Invalid order status.');
        }

        if ($role !== 'admin') {
            $allowed = self::ORDER_TRANSITIONS[$role][$order->status] ?? [];
            if (! in_array($nextStatus, $allowed, true)) {
                return $this->fail('This status change is not allowed right now.');
            }
        }

        $supportsSettlement = $this->orderSettlementColumnsAvailable();
        $nextPaymentStatus = (string) $order->payment_status;
        if ($order->payment_gateway === 'cod' && in_array($nextStatus, ['delivered', 'completed'], true)) {
            $nextPaymentStatus = 'paid';
        }

        $updatePayload = [
            'status' => $nextStatus,
            'payment_status' => $nextPaymentStatus,
            'delivered_at' => $nextStatus === 'delivered' ? now() : DB::raw('delivered_at'),
            'updated_at' => now(),
        ];
        if ($supportsSettlement) {
            $currentSettlement = $order->settlement_status ?? 'held';
            if ($currentSettlement !== 'released') {
                $updatePayload['settlement_status'] = in_array($nextStatus, ['delivered', 'completed'], true) && $nextPaymentStatus === 'paid'
                    ? 'ready_for_release'
                    : 'held';
            }
        }

        $restoresStock = in_array($nextStatus, ['cancelled', 'returned'], true)
            && ! in_array($order->status, ['cancelled', 'returned'], true);

        DB::transaction(function () use ($order, $orderId, $updatePayload, $nextStatus, $restoresStock, $request): void {
            DB::table('orders')->where('id', $orderId)->update($updatePayload);
            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'status' => $nextStatus,
                'changed_by' => $this->actor($request)->public_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($restoresStock) {
                foreach (DB::table('order_items')->where('order_id', $order->id)->get() as $item) {
                    DB::table('products')->where('id', $item->product_id)->increment('stock_qty', (int) $item->quantity);
                }
            }
        });

        if ($nextStatus === 'confirmed') {
            $this->createNotification(
                (string) $order->farmer_id,
                'order',
                'Order Confirmed',
                "Vendor confirmed order {$orderId}.",
                ['push', 'sms'],
            );
        }

        return $this->ok(['success' => true]);
    }

    public function adminOrders(Request $request): JsonResponse
    {
        $orders = DB::table('orders')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('paymentStatus'), fn ($query) => $query->where('payment_status', $request->input('paymentStatus')))
            ->when($request->filled('settlementStatus') && $this->orderSettlementColumnsAvailable(), fn ($query) => $query->where('settlement_status', $request->input('settlementStatus')))
            ->orderByDesc('placed_at')
            ->get()
            ->map(fn ($order) => $this->order($order));

        return $this->ok($orders);
    }

    public function updateOrderSettlement(Request $request, string $orderId): JsonResponse
    {
        if (! $this->orderSettlementColumnsAvailable()) {
            return $this->fail('Order settlement module is not available.', 422);
        }

        $order = DB::table('orders')->where('id', $orderId)->first();
        if (! $order) {
            return $this->fail('Order not found.', 404);
        }

        $action = strtolower(trim((string) $request->input('action', 'release')));
        if (! in_array($action, ['release', 'hold'], true)) {
            return $this->fail('Invalid settlement action.', 422);
        }

        if ($action === 'release') {
            if (! in_array($order->status, ['delivered', 'completed'], true)) {
                return $this->fail('Payment can be released only after delivery confirmation.');
            }
            if (($order->payment_status ?? 'pending') !== 'paid') {
                return $this->fail('Order payment is not completed yet.');
            }

            DB::table('orders')->where('id', $orderId)->update([
                'settlement_status' => 'released',
                'settlement_released_at' => now(),
                'settlement_released_by' => $this->actor($request)->public_id,
                'updated_at' => now(),
            ]);

            $vendor = DB::table('vendors')->where('id', $order->vendor_id)->first();
            if ($vendor?->user_id) {
                $this->createNotification(
                    $vendor->user_id,
                    'payment',
                    'Vendor Payout Released',
                    "Admin released settlement for order {$orderId}.",
                    ['push', 'sms']
                );
            }
            $this->createNotification(
                $order->farmer_id,
                'payment',
                'Order Settlement Completed',
                "Payment for order {$orderId} was released to the vendor after delivery confirmation.",
                ['push']
            );
            $this->createAuditLog(
                'order',
                'settlement_release',
                $this->actor($request)->name,
                "Released vendor settlement for order {$orderId}."
            );

            return $this->ok(['success' => true]);
        }

        DB::table('orders')->where('id', $orderId)->update([
            'settlement_status' => 'held',
            'settlement_released_at' => null,
            'settlement_released_by' => null,
            'updated_at' => now(),
        ]);
        $this->createAuditLog(
            'order',
            'settlement_hold',
            $this->actor($request)->name,
            "Kept vendor settlement on hold for order {$orderId}."
        );

        return $this->ok(['success' => true]);
    }

    public function cropListings(Request $request): JsonResponse
    {
        $listings = DB::table('crop_listings')
            ->when($request->filled('farmerId'), function ($query) use ($request) {
                $farmerId = trim((string) $request->input('farmerId'));
                $farmer = DB::table('farmers')
                    ->where('id', $farmerId)
                    ->orWhere('user_id', $farmerId)
                    ->first();
                $candidateIds = array_filter(array_unique([
                    $farmerId,
                    $farmer?->id,
                    $farmer?->user_id,
                ]));
                $query->whereIn('farmer_id', $candidateIds);
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($listing) => $this->cropListing($listing));

        return $this->ok($listings);
    }

    private function cropListingRules(bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $opt = $partial ? 'sometimes|nullable' : 'nullable';

        return [
            'productCategory' => "{$opt}|string|max:80",
            'cropType' => "{$req}|string|max:80",
            'variety' => "{$opt}|string|max:80",
            'quantityKg' => "{$req}|integer|min:1|max:10000000",
            'qualityGrade' => "{$opt}|in:A,B,C",
            'askingPrice' => "{$req}|numeric|min:0.01|max:10000000",
            'harvestDate' => "{$opt}|date",
            'district' => "{$opt}|string|max:80",
            'upazila' => "{$opt}|string|max:80",
            'photos' => "{$opt}|array",
            'status' => "{$opt}|in:active,matched,sold,expired",
        ];
    }

    public function createCropListing(Request $request): JsonResponse
    {
        $farmerIdInput = trim((string) $request->input('farmerId'));

        if ($this->isAdmin($request)) {
            $farmer = DB::table('farmers')->where('id', $farmerIdInput)->orWhere('user_id', $farmerIdInput)->first();
        } else {
            if ($farmerIdInput !== '' && ! $this->ownsId($request, $farmerIdInput)) {
                return $this->forbidden();
            }
            $farmer = DB::table('farmers')->where('user_id', $this->actor($request)->public_id)->first();
        }
        if (! $farmer) {
            return $this->fail('Farmer not found.', 404);
        }

        $data = $this->validateInput($request, $this->cropListingRules(false));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $photos = $this->photoList($data['photos'] ?? []);
        if ($photos === null) {
            return $this->fail('Attach up to '.self::MAX_PHOTOS.' images (PNG, JPEG, GIF or WebP, max 2 MB each).');
        }

        $id = $this->uniqueId('crop_listings', fn () => 'CRP-'.strtoupper(Str::random(6)));
        DB::table('crop_listings')->insert([
            'id' => $id,
            'tenant_id' => $farmer->tenant_id,
            'farmer_id' => $farmer->id,
            'product_category' => $data['productCategory'] ?? 'Fresh Vegetables',
            'crop_type' => $data['cropType'],
            'variety' => $data['variety'] ?? null,
            'quantity_kg' => $data['quantityKg'],
            'quality_grade' => $data['qualityGrade'] ?? 'A',
            'asking_price' => $data['askingPrice'],
            'harvest_date' => $data['harvestDate'] ?? null,
            'district' => $data['district'] ?? $farmer->district,
            'upazila' => $data['upazila'] ?? $farmer->upazila,
            'photos' => json_encode($photos),
            // New listings always start active; matched/sold are set by the deal flow.
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(['success' => true, 'listingId' => $id]);
    }

    private function editableCropListing(Request $request, string $listingId): object
    {
        $listing = DB::table('crop_listings')->where('id', $listingId)->first();
        if (! $listing) {
            return $this->fail('Listing not found.', 404);
        }

        if (! $this->isAdmin($request) && ! $this->ownsId($request, (string) $listing->farmer_id)) {
            return $this->forbidden();
        }

        return $listing;
    }

    public function updateCropListing(Request $request, string $listingId): JsonResponse
    {
        $listing = $this->editableCropListing($request, $listingId);
        if ($listing instanceof JsonResponse) {
            return $listing;
        }

        $data = $this->validateInput($request, $this->cropListingRules(true));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        // matchedCompanyId is intentionally not writable here; only the deal flow sets it.
        $columns = [
            'productCategory' => 'product_category',
            'cropType' => 'crop_type',
            'variety' => 'variety',
            'quantityKg' => 'quantity_kg',
            'qualityGrade' => 'quality_grade',
            'askingPrice' => 'asking_price',
            'harvestDate' => 'harvest_date',
            'district' => 'district',
            'upazila' => 'upazila',
        ];
        $payload = [];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $data)) {
                $payload[$column] = $data[$key];
            }
        }
        if (array_key_exists('photos', $data)) {
            $photos = $this->photoList($data['photos']);
            if ($photos === null) {
                return $this->fail('Attach up to '.self::MAX_PHOTOS.' images (PNG, JPEG, GIF or WebP, max 2 MB each).');
            }
            $payload['photos'] = json_encode($photos);
        }
        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'matched' && ! $this->isAdmin($request)) {
                return $this->fail('Listings become matched through the deal flow.');
            }
            $payload['status'] = $data['status'];
            if ($data['status'] === 'active') {
                $payload['matched_company_id'] = null;
            }
        }

        $payload['updated_at'] = now();

        DB::table('crop_listings')->where('id', $listing->id)->update($payload);

        return $this->ok(['success' => true]);
    }

    public function deleteCropListing(Request $request, string $listingId): JsonResponse
    {
        $listing = $this->editableCropListing($request, $listingId);
        if ($listing instanceof JsonResponse) {
            return $listing;
        }

        DB::table('crop_listings')->where('id', $listing->id)->delete();

        return $this->ok(['success' => true]);
    }

    public function expressInterest(Request $request): JsonResponse
    {
        $companyIdInput = trim((string) $request->input('companyId'));
        if ($companyIdInput !== '' && ! $this->ownsId($request, $companyIdInput)) {
            return $this->forbidden();
        }

        $company = DB::table('companies')->where('user_id', $this->actor($request)->public_id)->first();
        if (! $company) {
            return $this->fail('Company profile not found.', 404);
        }

        $data = $this->validateInput($request, [
            'listingId' => 'required|string|max:64',
            'quantityKg' => 'nullable|integer|min:1|max:10000000',
            'paymentGateway' => 'nullable|in:'.implode(',', self::DEAL_PAYMENT_GATEWAYS),
            'allowRepeat' => 'nullable|boolean',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $listing = DB::table('crop_listings')->where('id', $data['listingId'])->first();
        if (! $listing) {
            return $this->ok(['success' => false, 'message' => 'Listing not found.']);
        }
        if (in_array($listing->status, ['sold', 'expired'], true)) {
            return $this->ok(['success' => false, 'message' => 'This listing is no longer available.']);
        }

        $companyIds = array_values(array_unique(array_filter([(string) $company->id, (string) $company->user_id])));
        $allowRepeat = ! empty($data['allowRepeat']);
        $existing = DB::table('crop_deals')
            ->where('listing_id', $listing->id)
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', ['pending', 'confirmed', 'negotiating', 'locked', 'order_placed', 'accepted', 'delivered'])
            ->orderByDesc('created_at')
            ->first();
        if ($existing && ! $allowRepeat) {
            return $this->ok(['success' => true, 'matchId' => $existing->id, 'message' => 'Interest already sent.']);
        }

        $id = $this->uniqueId('crop_deals', fn () => 'deal_'.Str::lower(Str::random(12)));
        $requestedQty = min(max((int) ($data['quantityKg'] ?? $listing->quantity_kg), 1), (int) $listing->quantity_kg);
        $supportsDealPayment = $this->cropDealPaymentColumnsAvailable();

        DB::transaction(function () use ($id, $listing, $company, $requestedQty, $data, $supportsDealPayment): void {
            // Deals start pending: the farmer must confirm before negotiation or payment.
            $payload = [
                'id' => $id,
                'listing_id' => $listing->id,
                'company_id' => $company->id,
                'farmer_id' => $listing->farmer_id,
                'agreed_price' => $listing->asking_price,
                'quantity_kg' => $requestedQty,
                'commission_pct' => 3,
                'commission_amt' => round(((float) $listing->asking_price) * $requestedQty * 0.03, 2),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($supportsDealPayment) {
                $payload['payment_gateway'] = $data['paymentGateway'] ?? null;
                $payload['payment_status'] = 'pending';
            }

            DB::table('crop_deals')->insert($payload);

            DB::table('crop_listings')->where('id', $listing->id)->update([
                'status' => 'matched',
                'matched_company_id' => $company->id,
                'updated_at' => now(),
            ]);
        });

        $farmerUserId = DB::table('farmers')->where('id', $listing->farmer_id)->value('user_id') ?: $listing->farmer_id;
        $this->createNotification(
            (string) $farmerUserId,
            'crop_deal',
            'New Match Request',
            "{$company->company_name} wants to buy {$requestedQty} kg from your {$listing->crop_type} listing {$listing->id}.",
            ['push', 'sms'],
        );

        return $this->ok([
            'success' => true,
            'matchId' => $id,
            'message' => $allowRepeat ? 'Repeat match request sent.' : null,
        ]);
    }

    public function cropDeals(Request $request): JsonResponse
    {
        $supportsDealPayment = $this->cropDealPaymentColumnsAvailable();

        // Companies and farmers are always scoped to their own deals; admins may filter freely.
        $role = $this->actor($request)->role;
        $companyFilter = $role === 'company' ? (string) $this->actor($request)->public_id : trim((string) $request->input('companyId'));
        $farmerFilter = $role === 'farmer' ? (string) $this->actor($request)->public_id : trim((string) $request->input('farmerId'));
        if ($role === 'company') {
            $farmerFilter = '';
        }
        if ($role === 'farmer') {
            $companyFilter = '';
        }

        $deals = DB::table('crop_deals')
            ->when($companyFilter !== '', function ($query) use ($companyFilter) {
                $companyId = $companyFilter;
                $company = DB::table('companies')
                    ->where('id', $companyId)
                    ->orWhere('user_id', $companyId)
                    ->first();
                $candidateIds = array_filter(array_unique([
                    $companyId,
                    $company?->id,
                    $company?->user_id,
                ]));
                $query->whereIn('company_id', $candidateIds);
            })
            ->when($farmerFilter !== '', function ($query) use ($farmerFilter) {
                $farmerId = $farmerFilter;
                $farmer = DB::table('farmers')
                    ->where('id', $farmerId)
                    ->orWhere('user_id', $farmerId)
                    ->first();
                $candidateIds = array_filter(array_unique([
                    $farmerId,
                    $farmer?->id,
                    $farmer?->user_id,
                ]));
                $candidateListingIds = DB::table('crop_listings')
                    ->whereIn('farmer_id', $candidateIds)
                    ->pluck('id')
                    ->all();

                $query->where(function ($inner) use ($candidateIds, $candidateListingIds) {
                    $inner->whereIn('farmer_id', $candidateIds);
                    if (! empty($candidateListingIds)) {
                        $inner->orWhereIn('listing_id', $candidateListingIds);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($deal) use ($supportsDealPayment) {
                $company = $this->cachedRow('companies', 'id', $deal->company_id)
                    ?? $this->cachedRow('companies', 'user_id', $deal->company_id);
                $farmer = $this->cachedRow('farmers', 'id', $deal->farmer_id)
                    ?? $this->cachedRow('farmers', 'user_id', $deal->farmer_id);

                return [
                    'id' => $deal->id,
                    'listingId' => $deal->listing_id,
                    'companyId' => $deal->company_id,
                    'companyName' => $company?->company_name ?? 'Company',
                    'farmerId' => $deal->farmer_id,
                    'farmerName' => $farmer?->name_en ?? 'Farmer',
                    'agreedPrice' => (float) $deal->agreed_price,
                    'quantityKg' => (int) $deal->quantity_kg,
                    'commissionPct' => (float) $deal->commission_pct,
                    'commissionAmt' => (float) $deal->commission_amt,
                    'status' => $deal->status,
                    'confirmedAt' => $deal->confirmed_at,
                    'paymentGateway' => $supportsDealPayment ? ($deal->payment_gateway ?? null) : null,
                    'paymentStatus' => $supportsDealPayment ? ($deal->payment_status ?? null) : null,
                ];
            });

        return $this->ok($deals);
    }

    public function updateDealStatus(Request $request, string $dealId): JsonResponse
    {
        $deal = DB::table('crop_deals')->where('id', $dealId)->first();
        if (! $deal) {
            return $this->fail('Deal not found.', 404);
        }

        $nextStatus = trim((string) $request->input('status'));
        if ($nextStatus === '') {
            return $this->fail('Status is required.');
        }

        $allowedStatuses = [
            'pending', 'confirmed', 'negotiating', 'locked', 'order_placed',
            'accepted', 'delivered', 'completed', 'cancelled',
        ];
        if (! in_array($nextStatus, $allowedStatuses, true)) {
            return $this->fail('Invalid deal status.');
        }

        // The actor's role comes from the token, never from the request body.
        $actorRole = $this->actor($request)->role;
        $isParty = $actorRole === 'company'
            ? $this->ownsId($request, (string) $deal->company_id)
            : ($this->ownsId($request, (string) $deal->farmer_id)
                || $this->ownsId($request, (string) DB::table('crop_listings')->where('id', $deal->listing_id)->value('farmer_id')));
        if (! $isParty) {
            return $this->forbidden();
        }

        $data = $this->validateInput($request, [
            'agreedPrice' => 'nullable|numeric|min:0.01|max:10000000',
            'quantityKg' => 'nullable|integer|min:1|max:10000000',
            'paymentGateway' => 'nullable|in:'.implode(',', self::DEAL_PAYMENT_GATEWAYS),
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $allowedTransitions = [
            'pending' => [
                'farmer' => ['confirmed', 'cancelled'],
                'company' => ['cancelled'],
            ],
            'confirmed' => [
                'company' => ['negotiating', 'locked', 'cancelled'],
            ],
            'negotiating' => [
                'company' => ['negotiating', 'locked', 'cancelled'],
                'farmer' => ['negotiating', 'locked', 'cancelled'],
            ],
            'locked' => [
                'company' => ['order_placed', 'cancelled'],
            ],
            'order_placed' => [
                'farmer' => ['accepted', 'cancelled'],
            ],
            'accepted' => [
                'farmer' => ['delivered'],
            ],
            'delivered' => [
                'company' => ['completed'],
            ],
        ];
        $isSameStatus = $deal->status === $nextStatus;
        $validNext = $allowedTransitions[$deal->status][$actorRole] ?? [];
        if (! $isSameStatus && ! in_array($nextStatus, $validNext, true)) {
            return $this->fail('This status change is not allowed right now.');
        }

        $supportsDealPayment = $this->cropDealPaymentColumnsAvailable();
        $currentPaymentGateway = $supportsDealPayment ? ($deal->payment_gateway ?? null) : null;
        $currentPaymentStatus = $supportsDealPayment ? ($deal->payment_status ?? 'pending') : 'pending';
        $isPaid = $currentPaymentStatus === 'paid';

        // Terms can only change while the deal is still being negotiated.
        $termsChanging = (isset($data['agreedPrice']) && (float) $data['agreedPrice'] !== (float) $deal->agreed_price)
            || (isset($data['quantityKg']) && (int) $data['quantityKg'] !== (int) $deal->quantity_kg);
        $negotiable = in_array($deal->status, ['pending', 'confirmed', 'negotiating'], true);
        if ($termsChanging && ! $negotiable) {
            return $this->fail('Price and quantity can only change while the deal is being negotiated.');
        }

        $nextPrice = isset($data['agreedPrice']) ? (float) $data['agreedPrice'] : (float) $deal->agreed_price;
        $nextQty = isset($data['quantityKg']) ? (int) $data['quantityKg'] : (int) $deal->quantity_kg;
        $listingQty = (int) DB::table('crop_listings')->where('id', $deal->listing_id)->value('quantity_kg');
        if ($listingQty > 0 && $nextQty > $listingQty) {
            return $this->fail("Quantity cannot exceed the listed {$listingQty} kg.");
        }

        // Only the company chooses the gateway, and never after it has paid.
        $nextPaymentGateway = $currentPaymentGateway;
        if (isset($data['paymentGateway']) && $data['paymentGateway'] !== $currentPaymentGateway) {
            if ($actorRole !== 'company' || $isPaid) {
                return $this->fail('The payment method cannot be changed now.');
            }
            $nextPaymentGateway = $data['paymentGateway'];
        }

        // Payment status is never taken from the client. It becomes 'paid' only through a
        // verified gateway callback, or when the company confirms a cash-on-delivery deal is complete.
        $nextPaymentStatus = $currentPaymentStatus;
        if ($nextStatus === 'locked' && ! $isSameStatus) {
            $nextPaymentStatus = 'pending';
        }
        if ($nextStatus === 'completed' && $nextPaymentGateway === 'cod') {
            $nextPaymentStatus = 'paid';
        }
        if ($nextStatus === 'order_placed' && $supportsDealPayment
            && ($nextPaymentGateway ?: 'bkash') !== 'cod' && $nextPaymentStatus !== 'paid') {
            return $this->fail('Please complete payment before placing order.');
        }
        if ($nextStatus === 'completed' && $supportsDealPayment && $nextPaymentStatus !== 'paid') {
            return $this->fail('Payment for this deal has not been completed yet.');
        }

        if ($isSameStatus) {
            $payload = [
                'agreed_price' => $nextPrice,
                'quantity_kg' => $nextQty,
                'commission_amt' => round($nextPrice * $nextQty * (((float) $deal->commission_pct) / 100), 2),
                'updated_at' => now(),
            ];
            if ($supportsDealPayment) {
                $payload['payment_gateway'] = $nextPaymentGateway;
            }

            DB::table('crop_deals')->where('id', $deal->id)->update($payload);

            return $this->ok(['success' => true]);
        }

        DB::transaction(function () use ($deal, $nextStatus, $actorRole, $nextPrice, $nextQty, $nextPaymentGateway, $nextPaymentStatus, $supportsDealPayment): void {
            $companyUserId = DB::table('companies')->where('id', $deal->company_id)->value('user_id')
                ?: DB::table('companies')->where('user_id', $deal->company_id)->value('user_id');
            $companyTargets = array_values(array_filter(array_unique([$deal->company_id, $companyUserId])));
            $farmerUserId = DB::table('farmers')->where('id', $deal->farmer_id)->value('user_id')
                ?: DB::table('farmers')->where('user_id', $deal->farmer_id)->value('user_id');
            $farmerTargets = array_values(array_filter(array_unique([$deal->farmer_id, $farmerUserId])));
            $companyName = DB::table('companies')->where('id', $deal->company_id)->value('company_name')
                ?: DB::table('companies')->where('user_id', $deal->company_id)->value('company_name')
                ?: 'Company';
            $farmerName = DB::table('farmers')->where('id', $deal->farmer_id)->value('name_en')
                ?: DB::table('farmers')->where('user_id', $deal->farmer_id)->value('name_en')
                ?: 'Farmer';

            $payload = [
                'status' => $nextStatus,
                'agreed_price' => $nextPrice,
                'quantity_kg' => $nextQty,
                'commission_amt' => round($nextPrice * $nextQty * (((float) $deal->commission_pct) / 100), 2),
                'confirmed_at' => $nextStatus === 'confirmed' ? now() : DB::raw('confirmed_at'),
                'updated_at' => now(),
            ];
            if ($supportsDealPayment) {
                $payload['payment_gateway'] = $nextPaymentGateway;
                $payload['payment_status'] = $nextPaymentStatus;
                if ($nextStatus === 'locked') {
                    $payload['payment_ref'] = null;
                }
            }

            DB::table('crop_deals')->where('id', $deal->id)->update($payload);

            if (in_array($nextStatus, ['confirmed', 'negotiating', 'locked', 'order_placed', 'accepted', 'delivered'], true)) {
                DB::table('crop_listings')->where('id', $deal->listing_id)->update([
                    'status' => 'matched',
                    'matched_company_id' => $deal->company_id,
                    'updated_at' => now(),
                ]);
            }

            if ($nextStatus === 'completed') {
                DB::table('crop_listings')->where('id', $deal->listing_id)->update([
                    'status' => 'sold',
                    'matched_company_id' => $deal->company_id,
                    'updated_at' => now(),
                ]);
            }

            if ($nextStatus === 'confirmed') {
                foreach ($companyTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Deal Accepted by Farmer',
                        "{$farmerName} accepted your offer for listing {$deal->listing_id}.",
                        ['push', 'email'],
                    );
                }
                foreach ($farmerTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Offer Accepted Successfully',
                        "You accepted {$companyName}'s offer for listing {$deal->listing_id}.",
                        ['push', 'sms'],
                    );
                }
            } elseif ($nextStatus === 'negotiating') {
                if ($actorRole === 'company') {
                    foreach ($farmerTargets as $recipientId) {
                        $this->createNotification(
                            $recipientId,
                            'crop_deal',
                            'New Offer from Company',
                            "{$companyName} sent an updated offer for listing {$deal->listing_id}: ৳{$nextPrice}/kg, {$nextQty} kg.",
                            ['push', 'sms'],
                        );
                    }
                } else {
                    foreach ($companyTargets as $recipientId) {
                        $this->createNotification(
                            $recipientId,
                            'crop_deal',
                            'Final Offer from Farmer',
                            "{$farmerName} sent a final offer for listing {$deal->listing_id}: ৳{$nextPrice}/kg, {$nextQty} kg.",
                            ['push', 'email'],
                        );
                    }
                }
            } elseif ($nextStatus === 'locked') {
                foreach ($farmerTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Deal Locked',
                        "{$companyName} locked deal {$deal->id}. Next step: company will place order.",
                        ['push', 'sms'],
                    );
                }
            } elseif ($nextStatus === 'order_placed') {
                foreach ($farmerTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Order Placed by Company',
                        "{$companyName} placed order for deal {$deal->id}. Please accept or reject from My Orders.",
                        ['push', 'sms'],
                    );
                }
            } elseif ($nextStatus === 'accepted') {
                foreach ($companyTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Order Accepted by Farmer',
                        "{$farmerName} accepted order for deal {$deal->id}.",
                        ['push', 'email'],
                    );
                }
                foreach ($farmerTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Order Accepted Successfully',
                        "You accepted {$companyName}'s order for deal {$deal->id}.",
                        ['push', 'sms'],
                    );
                }
            } elseif ($nextStatus === 'delivered') {
                foreach ($companyTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Order Marked Delivered',
                        "{$farmerName} marked deal {$deal->id} as delivered. Please complete payment.",
                        ['push', 'email'],
                    );
                }
            } elseif ($nextStatus === 'completed') {
                foreach ($farmerTargets as $recipientId) {
                    $this->createNotification(
                        $recipientId,
                        'crop_deal',
                        'Payment Completed',
                        "{$companyName} completed payment for deal {$deal->id}.",
                        ['push', 'sms'],
                    );
                }
            }

            if ($nextStatus === 'cancelled') {
                $listing = DB::table('crop_listings')->where('id', $deal->listing_id)->first();
                if ($listing && $listing->matched_company_id === $deal->company_id) {
                    DB::table('crop_listings')->where('id', $deal->listing_id)->update([
                        'status' => 'active',
                        'matched_company_id' => null,
                        'updated_at' => now(),
                    ]);
                }

                if ($actorRole === 'farmer') {
                    foreach ($companyTargets as $recipientId) {
                        $this->createNotification(
                            $recipientId,
                            'crop_deal',
                            'Deal Rejected by Farmer',
                            "{$farmerName} rejected your offer for listing {$deal->listing_id}.",
                            ['push', 'email'],
                        );
                    }
                    foreach ($farmerTargets as $recipientId) {
                        $this->createNotification(
                            $recipientId,
                            'crop_deal',
                            'Deal Rejection Submitted',
                            "You rejected {$companyName}'s offer for listing {$deal->listing_id}.",
                            ['push', 'sms'],
                        );
                    }
                } else {
                    foreach ($farmerTargets as $recipientId) {
                        $this->createNotification(
                            $recipientId,
                            'crop_deal',
                            'Deal Cancelled by Company',
                            "{$companyName} cancelled deal {$deal->id}.",
                            ['push', 'sms'],
                        );
                    }
                }
            }
        });

        return $this->ok(['success' => true]);
    }

    /** The locked, unpaid deal the acting company wants to pay for, or an error response. */
    private function payableCompanyDeal(Request $request): object
    {
        $dealId = trim((string) $request->input('dealId'));
        $listingId = trim((string) $request->input('listingId'));
        if ($dealId === '' && $listingId === '') {
            return $this->fail('Deal ID or listing ID is required.');
        }

        $companyIds = $this->actorIds($request);
        $deal = DB::table('crop_deals')
            ->whereIn('company_id', $companyIds)
            ->when($dealId !== '', fn ($query) => $query->where('id', $dealId))
            ->when($dealId === '', fn ($query) => $query->where('listing_id', $listingId))
            ->orderByDesc('created_at')
            ->first();

        if (! $deal) {
            return $this->fail('Deal not found.', 404);
        }

        if ((string) $deal->status !== 'locked') {
            return $this->fail('Please lock the deal before starting payment.');
        }

        return $deal;
    }

    private function sslCommerzHost(): string
    {
        return config('services.sslcommerz.sandbox') ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    public function initiateSslCommerzPayment(Request $request): JsonResponse
    {
        $deal = $this->payableCompanyDeal($request);
        if ($deal instanceof JsonResponse) {
            return $deal;
        }

        if (($deal->payment_status ?? 'pending') === 'paid') {
            return $this->ok([
                'success' => true,
                'alreadyPaid' => true,
                'message' => 'Payment already completed for this deal. You can place order now.',
            ]);
        }

        $dealId = (string) $deal->id;
        $amount = max(1, round(((float) $deal->agreed_price) * ((int) $deal->quantity_kg), 2));
        $callbackBase = $this->backendUrl().'/api/v1/payments/sslcommerz';
        $storeId = (string) config('services.sslcommerz.store_id');
        $storePassword = (string) config('services.sslcommerz.store_password');
        $tranId = 'DEAL_'.Str::upper(Str::random(20));
        $company = DB::table('companies')->where('id', $deal->company_id)->first();
        $companyName = trim((string) ($company?->company_name ?: 'Company'));
        $field = fn (string $key, string $default) => mb_substr(trim((string) $request->input($key, $default)) ?: $default, 0, 100);
        $customerPhone = $field('customerPhone', (string) ($this->actor($request)->phone ?: '01700000000'));
        $customerAddress = $field('customerAddress', 'Dhaka');

        $gatewayPageUrl = '';
        if ($storeId !== '' && $storePassword !== '') {
            try {
                $response = Http::asForm()
                    ->timeout(30)
                    ->connectTimeout(30)
                    ->post($this->sslCommerzHost().'/gwprocess/v4/api.php', [
                        'store_id' => $storeId,
                        'store_passwd' => $storePassword,
                        'total_amount' => (string) $amount,
                        'currency' => 'BDT',
                        'tran_id' => $tranId,
                        'success_url' => $callbackBase.'/success',
                        'fail_url' => $callbackBase.'/fail',
                        'cancel_url' => $callbackBase.'/cancel',
                        'cus_name' => $companyName,
                        'cus_email' => (string) $this->actor($request)->email,
                        'cus_add1' => $customerAddress,
                        'cus_city' => $field('customerCity', 'Dhaka'),
                        'cus_state' => $field('customerState', 'Dhaka'),
                        'cus_postcode' => $field('customerPostcode', '1000'),
                        'cus_country' => 'Bangladesh',
                        'cus_phone' => $customerPhone,
                        'shipping_method' => 'NO',
                        'product_name' => 'Crop deal '.$deal->listing_id,
                        'product_category' => 'Agriculture',
                        'product_profile' => 'physical-goods',
                        'value_a' => $dealId,
                    ]);
                if ($response->successful()) {
                    $gatewayPageUrl = trim((string) ($response->json('GatewayPageURL') ?? ''));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($gatewayPageUrl === '') {
            if (! $this->paymentsMockEnabled()) {
                return $this->fail('Unable to start the payment right now. Please try again later.', 502);
            }
            $gatewayPageUrl = $this->sslCommerzMockGatewayUrl($dealId, $tranId, (string) $amount);
        }

        $requestedGateway = trim((string) $request->input('paymentGateway', $deal->payment_gateway ?: 'bkash'));
        DB::table('crop_deals')->where('id', $dealId)->update([
            'payment_gateway' => in_array($requestedGateway, self::DEAL_PAYMENT_GATEWAYS, true) ? $requestedGateway : 'sslcommerz',
            'payment_status' => 'pending',
            'payment_ref' => $tranId,
            'updated_at' => now(),
        ]);

        return $this->ok([
            'success' => true,
            'gatewayPageUrl' => $gatewayPageUrl,
            'tranId' => $tranId,
            'dealId' => $dealId,
        ]);
    }

    public function initiateStripePayment(Request $request): JsonResponse
    {
        $deal = $this->payableCompanyDeal($request);
        if ($deal instanceof JsonResponse) {
            return $deal;
        }

        if (($deal->payment_status ?? 'pending') === 'paid') {
            return $this->ok([
                'success' => true,
                'alreadyPaid' => true,
                'message' => 'Payment already completed for this deal. You can place order now.',
            ]);
        }

        $dealId = (string) $deal->id;
        $amount = max(1, round(((float) $deal->agreed_price) * ((int) $deal->quantity_kg), 2));
        $amountMinor = (int) max(100, round($amount * 100));
        $currency = strtolower(trim((string) config('services.stripe.currency', 'usd')));
        $session = $this->createStripeCheckoutSession(
            '/api/v1/payments/stripe',
            ['deal_id' => $dealId],
            $dealId,
            $amountMinor,
            $currency,
            'Crop Deal '.$deal->listing_id,
            'Payment for deal '.$dealId,
        );

        if ($session === null) {
            if (! $this->paymentsMockEnabled()) {
                return $this->fail('Unable to start the payment right now. Please try again later.', 502);
            }
            $sessionId = 'mock_'.Str::random(32);
            $session = ['id' => $sessionId, 'url' => $this->stripeMockGatewayUrl($dealId, $amountMinor, $currency, $sessionId)];
        }

        DB::table('crop_deals')->where('id', $dealId)->update([
            'payment_gateway' => 'stripe',
            'payment_status' => 'pending',
            'payment_ref' => $session['id'],
            'updated_at' => now(),
        ]);

        return $this->ok([
            'success' => true,
            'gatewayPageUrl' => $session['url'],
            'sessionId' => $session['id'],
            'dealId' => $dealId,
        ]);
    }

    /** @return array{id: string, url: string}|null */
    private function createStripeCheckoutSession(string $callbackPath, array $query, string $reference, int $amountMinor, string $currency, string $name, string $description): ?array
    {
        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return null;
        }

        $base = $this->backendUrl().$callbackPath;
        // http_build_query would encode the braces Stripe needs to substitute the session id.
        $successUrl = $base.'/success?'.http_build_query($query).'&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $base.'/cancel?'.http_build_query($query);
        $metadata = [];
        foreach ($query as $key => $value) {
            $metadata["metadata[{$key}]"] = $value;
        }

        try {
            $response = Http::asForm()
                ->timeout(30)
                ->connectTimeout(30)
                ->withToken($secret)
                ->post('https://api.stripe.com/v1/checkout/sessions', $metadata + [
                    'mode' => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'client_reference_id' => $reference,
                    'line_items[0][quantity]' => 1,
                    'line_items[0][price_data][currency]' => $currency,
                    'line_items[0][price_data][unit_amount]' => $amountMinor,
                    'line_items[0][price_data][product_data][name]' => $name,
                    'line_items[0][price_data][product_data][description]' => $description,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $id = trim((string) $response->json('id'));
        $url = trim((string) $response->json('url'));

        return $response->successful() && $id !== '' && $url !== '' ? ['id' => $id, 'url' => $url] : null;
    }

    /**
     * Confirm with Stripe (or the local simulator) that $sessionId is a paid checkout for $reference.
     * The session id must also equal the one stored when the payment was started.
     */
    private function stripeSessionIsPaid(string $sessionId, string $storedSessionId, string $reference, float $expectedAmount): bool
    {
        if ($sessionId === '' || ! hash_equals($storedSessionId, $sessionId)) {
            return false;
        }

        if (str_starts_with($sessionId, 'mock_')) {
            return $this->paymentsMockEnabled();
        }

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return false;
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(30)
                ->connectTimeout(30)
                ->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $response->successful()
            && $response->json('payment_status') === 'paid'
            && (string) $response->json('client_reference_id') === $reference
            && (int) $response->json('amount_total') >= (int) max(100, round($expectedAmount * 100));
    }

    public function stripeSuccess(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('deal_id'));
        $sessionId = trim((string) $request->input('session_id'));
        $deal = $dealId !== '' ? DB::table('crop_deals')->where('id', $dealId)->first() : null;

        $verified = $deal && $this->stripeSessionIsPaid(
            $sessionId,
            (string) ($deal->payment_ref ?? ''),
            (string) $deal->id,
            ((float) $deal->agreed_price) * ((int) $deal->quantity_kg),
        );

        if (! $verified) {
            return $this->sslPaymentPopupResponse('failed', $dealId, $sessionId, 'We could not verify this payment. Please try Pay Now again.');
        }

        if ($deal->payment_status !== 'paid') {
            DB::table('crop_deals')->where('id', $deal->id)->update([
                'payment_status' => 'paid',
                'payment_gateway' => 'stripe',
                'updated_at' => now(),
            ]);

            foreach ($this->companyNotificationTargets($deal) as $recipientId) {
                $this->createNotification(
                    $recipientId,
                    'payment',
                    'Payment Confirmed',
                    "Stripe payment completed for deal {$deal->id}. You can place order now.",
                    ['push', 'email'],
                );
            }
        }

        return $this->sslPaymentPopupResponse(
            'success',
            $dealId,
            $sessionId,
            'Payment successful. You can return to the Company Dashboard.',
        );
    }

    private function companyNotificationTargets(object $deal): array
    {
        $companyUserId = DB::table('companies')->where('id', $deal->company_id)->value('user_id')
            ?: DB::table('companies')->where('user_id', $deal->company_id)->value('user_id');

        return array_values(array_filter(array_unique([$deal->company_id, $companyUserId])));
    }

    public function stripeFail(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('deal_id'));
        $sessionId = trim((string) $request->input('session_id'));

        // Only the checkout that is currently in progress can be marked failed, and never a paid one.
        if ($dealId !== '' && $sessionId !== '') {
            DB::table('crop_deals')
                ->where('id', $dealId)
                ->where('payment_ref', $sessionId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'failed', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'failed',
            $dealId,
            $sessionId,
            'Payment failed. Please try Pay Now again.',
        );
    }

    public function stripeCancel(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('deal_id'));
        $sessionId = trim((string) $request->input('session_id'));

        // Cancelling never touches a paid deal; an unpaid one simply stays pending.
        if ($dealId !== '') {
            DB::table('crop_deals')
                ->where('id', $dealId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'pending', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'cancelled',
            $dealId,
            $sessionId,
            'Payment was cancelled.',
        );
    }

    public function stripeMockGateway(Request $request): HttpResponse
    {
        abort_unless($this->paymentsMockEnabled(), 404);

        $dealId = trim((string) $request->query('deal_id'));
        $sessionId = trim((string) $request->query('session_id'));
        $amount = trim((string) $request->query('amount', '0'));
        $currency = trim((string) $request->query('currency', 'usd'));
        $base = $this->backendUrl().'/api/v1/payments/stripe';

        return $this->mockGatewayPage(
            'Stripe Checkout (Local Mock)',
            ['Deal ID' => $dealId, 'Amount' => strtoupper($currency).' '.$amount],
            $base.'/success?'.http_build_query(['deal_id' => $dealId, 'session_id' => $sessionId]),
            $base.'/fail?'.http_build_query(['deal_id' => $dealId, 'session_id' => $sessionId]),
            $base.'/cancel?'.http_build_query(['deal_id' => $dealId, 'session_id' => $sessionId]),
        );
    }

    private function mockGatewayPage(string $title, array $details, string $successUrl, string $failUrl, string $cancelUrl): HttpResponse
    {
        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($details as $label => $value) {
            $rows .= '<p>'.$e($label).': <strong>'.$e((string) $value).'</strong></p>';
        }

        $html = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>{$e($title)}</title></head>
<body style="font-family: sans-serif; max-width: 640px; margin: 48px auto; padding: 24px;">
  <h2>{$e($title)}</h2>
  {$rows}
  <p>Local payment simulator (PAYMENTS_MOCK_ENABLED=true). Use a test action below.</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;">
    <a href="{$e($successUrl)}" style="padding:10px 16px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;">Simulate Success</a>
    <a href="{$e($failUrl)}" style="padding:10px 16px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;">Simulate Fail</a>
    <a href="{$e($cancelUrl)}" style="padding:10px 16px;background:#6b7280;color:#fff;border-radius:8px;text-decoration:none;">Simulate Cancel</a>
  </div>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function initiateOrderStripePayment(Request $request): JsonResponse
    {
        $orderId = trim((string) $request->input('orderId'));
        if ($orderId === '') {
            return $this->fail('Order ID is required.');
        }

        $order = DB::table('orders')->where('id', $orderId)->first();
        if (! $order) {
            return $this->fail('Order not found.', 404);
        }

        if (! $this->ownsId($request, (string) $order->farmer_id)) {
            return $this->fail('Order does not belong to this farmer.', 403);
        }

        if (($order->payment_status ?? 'pending') === 'paid') {
            return $this->ok([
                'success' => true,
                'alreadyPaid' => true,
                'message' => 'Payment already completed for this order.',
            ]);
        }

        if (in_array($order->status, ['cancelled', 'returned'], true)) {
            return $this->fail('This order can no longer be paid.');
        }

        $amount = max(1, round((float) $order->total_amount, 2));
        $amountMinor = (int) max(100, round($amount * 100));
        $currency = strtolower(trim((string) config('services.stripe.currency', 'usd')));
        $session = $this->createStripeCheckoutSession(
            '/api/v1/payments/orders/stripe',
            ['order_id' => $orderId],
            $orderId,
            $amountMinor,
            $currency,
            'Order '.$orderId,
            'Marketplace payment for order '.$orderId,
        );

        if ($session === null) {
            if (! $this->paymentsMockEnabled()) {
                return $this->fail('Unable to start the payment right now. Please try again later.', 502);
            }
            $sessionId = 'mock_'.Str::random(32);
            $session = ['id' => $sessionId, 'url' => $this->orderStripeMockGatewayUrl($orderId, $amountMinor, $currency, $sessionId)];
        }

        DB::table('orders')->where('id', $orderId)->update([
            'payment_gateway' => 'stripe',
            'payment_status' => 'pending',
            'txn_ref' => $session['id'],
            'updated_at' => now(),
        ]);

        return $this->ok([
            'success' => true,
            'gatewayPageUrl' => $session['url'],
            'sessionId' => $session['id'],
            'orderId' => $orderId,
        ]);
    }

    public function orderStripeSuccess(Request $request): HttpResponse
    {
        $orderId = trim((string) $request->input('order_id'));
        $sessionId = trim((string) $request->input('session_id'));
        $order = $orderId !== '' ? DB::table('orders')->where('id', $orderId)->first() : null;

        $verified = $order && $this->stripeSessionIsPaid(
            $sessionId,
            (string) ($order->txn_ref ?? ''),
            (string) $order->id,
            (float) $order->total_amount,
        );

        if (! $verified) {
            return $this->sslPaymentPopupResponse('failed', $orderId, $sessionId, 'We could not verify this payment. Please try Stripe payment again.');
        }

        if ($order->payment_status !== 'paid') {
            $orderUpdatePayload = [
                'payment_status' => 'paid',
                'payment_gateway' => 'stripe',
                'updated_at' => now(),
            ];
            if ($this->orderSettlementColumnsAvailable() && (($order->settlement_status ?? 'held') !== 'released')) {
                $orderUpdatePayload['settlement_status'] = in_array((string) $order->status, ['delivered', 'completed'], true) ? 'ready_for_release' : 'held';
            }

            DB::table('orders')->where('id', $order->id)->update($orderUpdatePayload);

            $this->createNotification(
                (string) $order->farmer_id,
                'payment',
                'Order Payment Confirmed',
                "Stripe payment completed for order {$order->id}.",
                ['push', 'sms'],
            );
            $vendorUserId = DB::table('vendors')->where('id', $order->vendor_id)->value('user_id');
            if ($vendorUserId) {
                $this->createNotification(
                    (string) $vendorUserId,
                    'payment',
                    'Customer Payment Received',
                    "Order {$order->id} payment is confirmed via Stripe.",
                    ['push', 'sms'],
                );
            }
        }

        return $this->sslPaymentPopupResponse(
            'success',
            $orderId,
            $sessionId,
            'Payment successful. You can return to the Farmer Dashboard.',
        );
    }

    public function orderStripeFail(Request $request): HttpResponse
    {
        $orderId = trim((string) $request->input('order_id'));
        $sessionId = trim((string) $request->input('session_id'));

        if ($orderId !== '' && $sessionId !== '') {
            DB::table('orders')
                ->where('id', $orderId)
                ->where('txn_ref', $sessionId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'failed', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'failed',
            $orderId,
            $sessionId,
            'Payment failed. Please try Stripe payment again.',
        );
    }

    public function orderStripeCancel(Request $request): HttpResponse
    {
        $orderId = trim((string) $request->input('order_id'));
        $sessionId = trim((string) $request->input('session_id'));

        if ($orderId !== '') {
            DB::table('orders')
                ->where('id', $orderId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'pending', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'cancelled',
            $orderId,
            $sessionId,
            'Payment was cancelled.',
        );
    }

    public function orderStripeMockGateway(Request $request): HttpResponse
    {
        abort_unless($this->paymentsMockEnabled(), 404);

        $orderId = trim((string) $request->query('order_id'));
        $sessionId = trim((string) $request->query('session_id'));
        $amount = trim((string) $request->query('amount', '0'));
        $currency = trim((string) $request->query('currency', 'usd'));
        $base = $this->backendUrl().'/api/v1/payments/orders/stripe';
        $query = ['order_id' => $orderId, 'session_id' => $sessionId];

        return $this->mockGatewayPage(
            'Stripe Checkout (Order Mock)',
            ['Order ID' => $orderId, 'Amount' => strtoupper($currency).' '.$amount],
            $base.'/success?'.http_build_query($query),
            $base.'/fail?'.http_build_query($query),
            $base.'/cancel?'.http_build_query($query),
        );
    }

    /** Validate an SSLCommerz payment server-side (or via the local simulator) for the stored transaction. */
    private function sslCommerzPaymentIsValid(Request $request, object $deal, string $tranId): bool
    {
        if ($tranId === '' || ! hash_equals((string) ($deal->payment_ref ?? ''), $tranId)) {
            return false;
        }

        $valId = trim((string) $request->input('val_id'));
        $storeId = (string) config('services.sslcommerz.store_id');
        $storePassword = (string) config('services.sslcommerz.store_password');

        if ($valId === '') {
            // Only the local simulator redirects without a val_id.
            return $this->paymentsMockEnabled() && strtoupper((string) $request->input('status')) === 'VALID';
        }

        if ($storeId === '' || $storePassword === '') {
            return false;
        }

        try {
            $response = Http::timeout(30)->connectTimeout(30)->get($this->sslCommerzHost().'/validator/api/validationserverAPI.php', [
                'val_id' => $valId,
                'store_id' => $storeId,
                'store_passwd' => $storePassword,
                'format' => 'json',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        $expectedAmount = round(((float) $deal->agreed_price) * ((int) $deal->quantity_kg), 2);

        return $response->successful()
            && in_array(strtoupper((string) $response->json('status')), ['VALID', 'VALIDATED'], true)
            && (string) $response->json('tran_id') === $tranId
            && strtoupper((string) $response->json('currency_type', $response->json('currency'))) === 'BDT'
            && (float) $response->json('currency_amount', $response->json('amount')) + 0.01 >= max(1, $expectedAmount);
    }

    public function sslCommerzSuccess(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('value_a'));
        $tranId = trim((string) $request->input('tran_id'));
        $deal = $dealId !== '' ? DB::table('crop_deals')->where('id', $dealId)->first() : null;

        if (! $deal || ! $this->sslCommerzPaymentIsValid($request, $deal, $tranId)) {
            return $this->sslPaymentPopupResponse('failed', $dealId, $tranId, 'We could not verify this payment. Please try Pay Now again.');
        }

        if ($deal->payment_status !== 'paid') {
            DB::table('crop_deals')->where('id', $deal->id)->update([
                'payment_status' => 'paid',
                'updated_at' => now(),
            ]);

            foreach ($this->companyNotificationTargets($deal) as $recipientId) {
                $this->createNotification(
                    $recipientId,
                    'payment',
                    'Payment Confirmed',
                    "Payment completed for deal {$deal->id}. You can place order now.",
                    ['push', 'email'],
                );
            }
        }

        return $this->sslPaymentPopupResponse(
            'success',
            $dealId,
            $tranId,
            'Payment successful. You can return to the Company Dashboard.',
        );
    }

    public function sslCommerzFail(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('value_a'));
        $tranId = trim((string) $request->input('tran_id'));

        if ($dealId !== '' && $tranId !== '') {
            DB::table('crop_deals')
                ->where('id', $dealId)
                ->where('payment_ref', $tranId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'failed', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'failed',
            $dealId,
            $tranId,
            'Payment failed. Please try Pay Now again.',
        );
    }

    public function sslCommerzCancel(Request $request): HttpResponse
    {
        $dealId = trim((string) $request->input('value_a'));
        $tranId = trim((string) $request->input('tran_id'));

        if ($dealId !== '' && $tranId !== '') {
            DB::table('crop_deals')
                ->where('id', $dealId)
                ->where('payment_ref', $tranId)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'pending', 'updated_at' => now()]);
        }

        return $this->sslPaymentPopupResponse(
            'cancelled',
            $dealId,
            $tranId,
            'Payment was cancelled.',
        );
    }

    public function sslCommerzMockGateway(Request $request): HttpResponse
    {
        abort_unless($this->paymentsMockEnabled(), 404);

        $dealId = trim((string) $request->query('deal_id'));
        $tranId = trim((string) $request->query('tran_id'));
        $amount = trim((string) $request->query('amount', '0'));
        $base = $this->backendUrl().'/api/v1/payments/sslcommerz';
        $query = fn (string $status) => http_build_query(['value_a' => $dealId, 'tran_id' => $tranId, 'status' => $status]);

        return $this->mockGatewayPage(
            'SSLCommerz Sandbox (Local Mock)',
            ['Deal ID' => $dealId, 'Transaction ID' => $tranId, 'Amount' => 'BDT '.$amount],
            $base.'/success?'.$query('VALID'),
            $base.'/fail?'.$query('FAILED'),
            $base.'/cancel?'.$query('CANCELLED'),
        );
    }

    private function sslPaymentPopupResponse(string $status, string $dealId, string $tranId, string $message): HttpResponse
    {
        // JSON_HEX_* keeps request-supplied ids from closing the <script> block.
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $payload = json_encode([
            'type' => 'sslcommerz-payment-status',
            'status' => $status,
            'dealId' => mb_substr($dealId, 0, 100),
            'tranId' => mb_substr($tranId, 0, 200),
            'message' => $message,
        ], $flags);
        $targetOrigin = json_encode($this->frontendUrl(), $flags);

        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $script = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Payment Status</title></head>
<body style="font-family: sans-serif; padding: 24px;">
<h3>{$safeMessage}</h3>
<p>You can close this tab now.</p>
<script>
(function () {
  var payload = {$payload};
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(payload, {$targetOrigin});
    }
  } catch (e) {}
  setTimeout(function () { window.close(); }, 1200);
})();
</script>
</body>
</html>
HTML;

        return response($script, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function sslCommerzMockGatewayUrl(string $dealId, string $tranId, string $amount): string
    {
        return $this->backendUrl().'/api/v1/payments/sslcommerz/mock-gateway?'.http_build_query([
            'deal_id' => $dealId,
            'tran_id' => $tranId,
            'amount' => $amount,
        ]);
    }

    private function stripeMockGatewayUrl(string $dealId, int $amountMinor, string $currency, string $sessionId): string
    {
        return $this->backendUrl().'/api/v1/payments/stripe/mock-gateway?'.http_build_query([
            'deal_id' => $dealId,
            'session_id' => $sessionId,
            'amount' => $amountMinor,
            'currency' => $currency,
        ]);
    }

    private function orderStripeMockGatewayUrl(string $orderId, int $amountMinor, string $currency, string $sessionId): string
    {
        return $this->backendUrl().'/api/v1/payments/orders/stripe/mock-gateway?'.http_build_query([
            'order_id' => $orderId,
            'session_id' => $sessionId,
            'amount' => $amountMinor,
            'currency' => $currency,
        ]);
    }

    public function cropPrices(): JsonResponse
    {
        $prices = DB::table('crop_prices')->orderBy('crop_type')->get()->map(fn ($price) => [
            'cropType' => $price->crop_type,
            'currentPrice' => (float) $price->current_price,
            'unit' => $price->unit,
            'change7d' => (float) $price->change_7d,
            'changePercent' => (float) $price->change_percent,
            'trend' => $price->trend,
            'lastUpdated' => $price->updated_at,
            'history' => $this->arrayValue($price->history),
        ]);

        return $this->ok($prices);
    }

    public function subscribePriceAlert(Request $request): JsonResponse
    {
        if ($request->filled('farmerId') && ! $this->ownsId($request, (string) $request->input('farmerId'))) {
            return $this->forbidden();
        }

        $cropType = trim((string) $request->input('cropType'));
        if ($cropType === '' || mb_strlen($cropType) > 80) {
            return $this->fail('Choose a crop to receive price alerts for.');
        }

        DB::table('price_alerts')->insert([
            'farmer_id' => $this->actorRoleId($request),
            'crop_type' => $cropType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(['success' => true]);
    }

    public function notifications(Request $request, string $userId): JsonResponse
    {
        if (! $this->ownsId($request, $userId)) {
            return $this->forbidden();
        }

        $notifications = DB::table('notifications')->whereIn('user_id', $this->actorIds($request))->orderByDesc('created_at')->get()->map(fn ($notification) => [
            'id' => $notification->id,
            'userId' => $notification->user_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'channel' => $this->arrayValue($notification->channel),
            'isRead' => (bool) $notification->is_read,
            'createdAt' => $notification->created_at,
        ]);

        return $this->ok($notifications);
    }

    public function markNotificationRead(Request $request, string $notificationId): JsonResponse
    {
        $updated = DB::table('notifications')
            ->where('id', $notificationId)
            ->whereIn('user_id', $this->actorIds($request))
            ->update(['is_read' => true, 'updated_at' => now()]);

        return $updated ? $this->ok(['success' => true]) : $this->fail('Notification not found.', 404);
    }

    public function markAllNotificationsRead(Request $request, string $userId): JsonResponse
    {
        if (! $this->ownsId($request, $userId)) {
            return $this->forbidden();
        }

        DB::table('notifications')->whereIn('user_id', $this->actorIds($request))->update(['is_read' => true, 'updated_at' => now()]);

        return $this->ok(['success' => true]);
    }

    public function settings(Request $request, string $userId): JsonResponse
    {
        if (! $this->ownsId($request, $userId)) {
            return $this->forbidden();
        }

        $settings = DB::table('user_settings')->where('user_id', $userId)->first();

        return $this->ok([
            'emailNotifications' => $settings ? (bool) $settings->email_notifications : true,
            'pushNotifications' => $settings ? (bool) $settings->push_notifications : true,
            'outbreakWarnings' => $settings ? (bool) $settings->outbreak_warnings : true,
            'urgentAdvisory' => $settings ? (bool) $settings->urgent_advisory : true,
            'newCaseAlert' => $settings ? (bool) $settings->new_case_alert : true,
        ]);
    }

    public function updateSettings(Request $request, string $userId): JsonResponse
    {
        if (! $this->ownsId($request, $userId)) {
            return $this->forbidden();
        }

        $payload = [
            'email_notifications' => $request->boolean('emailNotifications'),
            'push_notifications' => $request->boolean('pushNotifications'),
            'outbreak_warnings' => $request->boolean('outbreakWarnings'),
            'urgent_advisory' => $request->boolean('urgentAdvisory'),
            'new_case_alert' => $request->boolean('newCaseAlert'),
            'updated_at' => now(),
        ];

        if (DB::table('user_settings')->where('user_id', $userId)->exists()) {
            DB::table('user_settings')->where('user_id', $userId)->update($payload);
        } else {
            DB::table('user_settings')->insert([
                'user_id' => $userId,
                ...$payload,
                'created_at' => now(),
            ]);
        }

        return $this->ok(['success' => true]);
    }

    public function changePassword(Request $request, string $userId): JsonResponse
    {
        $user = $this->userByPublicOrRoleId($userId);

        if (! $user) {
            return $this->fail('User not found.', 404);
        }

        if ($user->public_id !== $this->actor($request)->public_id) {
            return $this->forbidden();
        }

        $currentPassword = (string) $request->input('currentPassword');
        $nextPassword = (string) $request->input('nextPassword');

        if ($nextPassword === '') {
            return $this->fail('New password is required.');
        }

        if (mb_strlen($nextPassword) < 8 || mb_strlen($nextPassword) > 200) {
            return $this->fail('New password must be between 8 and 200 characters long.');
        }

        if (! Hash::check($currentPassword, $user->password)) {
            return $this->fail('Current password is incorrect.', 401);
        }

        DB::table('users')
            ->where('public_id', $user->public_id)
            ->update([
                'password' => Hash::make($nextPassword),
                'updated_at' => now(),
            ]);

        // Sign out every other session; keep the one that made this change.
        DB::table('api_tokens')
            ->where('user_public_id', $user->public_id)
            ->where('id', '!=', $request->attributes->get('authTokenId'))
            ->delete();

        return $this->ok(['success' => true]);
    }

    public function deleteUserAccount(Request $request, string $userId): JsonResponse
    {
        $user = $this->userByPublicOrRoleId($userId);

        if (! $user) {
            return $this->fail('User not found.', 404);
        }

        if (! $this->isAdmin($request) && $user->public_id !== $this->actor($request)->public_id) {
            return $this->forbidden();
        }

        DB::transaction(function () use ($user): void {
            $roleRecordId = match ($user->role) {
                'farmer' => DB::table('farmers')->where('user_id', $user->public_id)->value('id'),
                'officer' => DB::table('officers')->where('user_id', $user->public_id)->value('id'),
                'vendor' => DB::table('vendors')->where('user_id', $user->public_id)->value('id'),
                'company' => DB::table('companies')->where('user_id', $user->public_id)->value('id'),
                default => null,
            };

            DB::table('notifications')->where('user_id', $user->public_id)->delete();
            DB::table('user_settings')->where('user_id', $user->public_id)->delete();
            DB::table('api_tokens')->where('user_public_id', $user->public_id)->delete();
            DB::table('auth_otps')
                ->where('user_id', $user->public_id)
                ->orWhere('email', $this->normalizedEmail($user->email))
                ->delete();

            DB::table('cooperatives')
                ->where('leader_id', $user->public_id)
                ->update(['leader_id' => null, 'updated_at' => now()]);

            DB::table('tenants')
                ->where('admin_user_id', $user->public_id)
                ->update(['admin_user_id' => null, 'updated_at' => now()]);

            match ($user->role) {
                'farmer' => DB::table('farmers')->where('id', $roleRecordId)->delete(),
                'officer' => DB::table('officers')->where('id', $roleRecordId)->delete(),
                'vendor' => DB::table('vendors')->where('id', $roleRecordId)->delete(),
                'company' => DB::table('companies')->where('id', $roleRecordId)->delete(),
                default => null,
            };

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('users')->where('public_id', $user->public_id)->delete();
        });

        return $this->ok(['success' => true]);
    }

    public function tenants(): JsonResponse
    {
        return $this->ok(DB::table('tenants')->orderByDesc('created_at')->get()->map(fn ($tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'planTier' => $tenant->plan_tier,
            'adminUserId' => $tenant->admin_user_id,
            'status' => $tenant->status,
            'farmerCount' => (int) $tenant->farmer_count,
            'createdAt' => $tenant->created_at,
            'mrr' => (float) $tenant->mrr,
        ]));
    }

    private const TENANT_PLAN_PRICES = ['basic' => 999, 'standard' => 2999, 'professional' => 5999, 'enterprise' => 0];

    public function createTenant(Request $request): JsonResponse
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:120',
            'subdomain' => ['required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', 'unique:tenants,subdomain'],
            'planTier' => 'nullable|in:'.implode(',', array_keys(self::TENANT_PLAN_PRICES)),
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $planTier = $data['planTier'] ?? 'basic';
        $id = $this->uniqueId('tenants', fn () => 'tenant_'.Str::lower(Str::random(8)));
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => $data['name'],
            'subdomain' => $data['subdomain'],
            'plan_tier' => $planTier,
            'admin_user_id' => $this->actor($request)->public_id,
            'status' => 'trial',
            'farmer_count' => 0,
            'mrr' => self::TENANT_PLAN_PRICES[$planTier],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createAuditLog('tenant', 'create', $this->actor($request)->name, "Created tenant {$data['name']} ({$id}).");

        return $this->ok(['success' => true, 'tenantId' => $id]);
    }

    public function updateTenant(Request $request, string $tenantId): JsonResponse
    {
        if (! DB::table('tenants')->where('id', $tenantId)->exists()) {
            return $this->fail('Tenant not found.', 404);
        }

        $data = $this->validateInput($request, [
            'name' => 'sometimes|required|string|max:120',
            'subdomain' => ['sometimes', 'required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', 'unique:tenants,subdomain,'.$tenantId.',id'],
            'planTier' => 'sometimes|required|in:'.implode(',', array_keys(self::TENANT_PLAN_PRICES)),
            'status' => 'sometimes|required|in:active,suspended,trial',
            'adminUserId' => 'sometimes|nullable|string|exists:users,public_id',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        // Explicit allow-list: id, mrr and counters are never client-writable.
        $update = [];
        foreach (['name' => 'name', 'subdomain' => 'subdomain', 'planTier' => 'plan_tier', 'status' => 'status', 'adminUserId' => 'admin_user_id'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $update[$column] = $data[$key];
            }
        }
        if (array_key_exists('planTier', $data)) {
            $update['mrr'] = self::TENANT_PLAN_PRICES[$data['planTier']];
        }
        $update['updated_at'] = now();

        DB::table('tenants')->where('id', $tenantId)->update($update);
        $this->createAuditLog('tenant', 'update', $this->actor($request)->name, "Updated tenant {$tenantId}: ".implode(', ', array_keys($data)).'.');

        return $this->ok(['success' => true]);
    }

    public function adminStats(): JsonResponse
    {
        $supportsSettlement = $this->orderSettlementColumnsAvailable();
        $releasedAmount = $supportsSettlement
            ? (float) DB::table('orders')->where('settlement_status', 'released')->sum('total_amount')
            : 0.0;
        $heldAmount = $supportsSettlement
            ? (float) DB::table('orders')->whereIn('settlement_status', ['held', 'ready_for_release'])->sum('total_amount')
            : 0.0;
        $totalAdvisories = DB::table('advisory_cases')->count();
        $answeredAdvisories = DB::table('advisory_cases')->whereIn('status', ['responded', 'resolved', 'closed'])->count();

        return $this->ok([
            'totalFarmers' => DB::table('farmers')->count(),
            'totalOfficers' => DB::table('officers')->count(),
            'totalVendors' => DB::table('vendors')->count(),
            'totalAdvisories' => $totalAdvisories,
            'activeAdvisories' => DB::table('advisory_cases')->whereNotIn('status', ['closed', 'responded', 'resolved'])->count(),
            'totalOrders' => DB::table('orders')->count(),
            'pendingSettlements' => $supportsSettlement ? DB::table('orders')->where('settlement_status', 'ready_for_release')->count() : 0,
            'heldSettlementAmount' => $heldAmount,
            'releasedSettlementAmount' => $releasedAmount,
            'mrr' => (float) DB::table('tenants')->sum('mrr'),
            // Share of advisory cases that have received an officer response.
            'advisoryDeliveryRate' => $totalAdvisories > 0 ? round($answeredAdvisories / $totalAdvisories * 100, 1) : 0.0,
        ]);
    }

    public function updateFarmerAdminState(Request $request, string $farmerId): JsonResponse
    {
        $farmer = DB::table('farmers')->where('id', $farmerId)->first();
        if (! $farmer) {
            return $this->fail('Farmer not found.', 404);
        }

        $payload = ['updated_at' => now()];
        $hasChange = false;

        if ($request->exists('verified')) {
            $payload['verified'] = $request->boolean('verified');
            $hasChange = true;
        }

        if ($request->exists('blocked')) {
            $payload['blocked'] = $request->boolean('blocked');
            $hasChange = true;
        }

        if (! $hasChange) {
            return $this->fail('No farmer moderation field provided.');
        }

        DB::table('farmers')->where('id', $farmerId)->update($payload);

        if (! empty($payload['blocked'])) {
            DB::table('api_tokens')->where('user_public_id', $farmer->user_id)->delete();
        }

        $verifiedLabel = array_key_exists('verified', $payload) ? ((bool) $payload['verified'] ? 'verified' : 'unverified') : null;
        $blockedLabel = array_key_exists('blocked', $payload) ? ((bool) $payload['blocked'] ? 'blocked' : 'unblocked') : null;
        $details = collect([$verifiedLabel, $blockedLabel])->filter()->implode(', ');
        $this->createAuditLog('farmer', 'moderate', $this->actor($request)->name, trim("Updated farmer {$farmerId} state: {$details}."));

        return $this->ok(['success' => true]);
    }

    public function updateOfficerAdminState(Request $request, string $officerId): JsonResponse
    {
        $officer = DB::table('officers')
            ->where('id', $officerId)
            ->orWhere('user_id', $officerId)
            ->orWhere('officer_id', $officerId)
            ->first();
        if (! $officer) {
            return $this->fail('Officer not found.', 404);
        }

        $payload = ['updated_at' => now()];
        $hasChange = false;

        if ($request->exists('active')) {
            $isActive = $request->boolean('active');
            $payload['active'] = $isActive;
            $payload['availability_status'] = $isActive ? 'available' : 'offline';
            $hasChange = true;
        }

        if ($request->exists('assignedRegion') || $request->exists('regionDistricts')) {
            $regionDistricts = $request->exists('regionDistricts')
                ? collect($request->input('regionDistricts', []))
                : collect(explode(',', (string) $request->input('assignedRegion', '')));
            $regionDistricts = $regionDistricts
                ->map(fn ($item) => trim((string) $item))
                ->filter(fn ($item) => $item !== '')
                ->values();

            $payload['region_districts'] = json_encode($regionDistricts->all());
            $hasChange = true;

            $primaryDistrict = $regionDistricts->first();
            if ($primaryDistrict) {
                DB::table('users')
                    ->where('public_id', $officer->user_id)
                    ->update(['district' => $primaryDistrict, 'updated_at' => now()]);
            }
        }

        if (! $hasChange) {
            return $this->fail('No officer moderation field provided.');
        }

        DB::table('officers')->where('id', $officer->id)->update($payload);

        if (array_key_exists('active', $payload) && ! $payload['active']) {
            DB::table('api_tokens')->where('user_public_id', $officer->user_id)->delete();
        }

        $activeLabel = array_key_exists('active', $payload) ? ((bool) $payload['active'] ? 'activated' : 'disabled') : null;
        $regionLabel = array_key_exists('region_districts', $payload) ? 'region updated' : null;
        $details = collect([$activeLabel, $regionLabel])->filter()->implode(', ');
        $this->createAuditLog('officer', 'update', $this->actor($request)->name, trim("Updated officer {$officer->id} state: {$details}."));

        return $this->ok(['success' => true]);
    }

    public function auditLogs(): JsonResponse
    {
        return $this->ok(DB::table('audit_logs')->orderByDesc('created_at')->get()->map(fn ($log) => [
            'id' => $log->id,
            'entity' => $log->entity,
            'action' => $log->action,
            'actor' => $log->actor,
            'details' => $log->details,
            'createdAt' => $log->created_at,
        ]));
    }

    private function createNotification(string $userId, string $type, string $title, string $message, array $channel): void
    {
        DB::table('notifications')->insert([
            'id' => 'notif_'.Str::random(12),
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'channel' => json_encode($channel),
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAuditLog(string $entity, string $action, string $actor, string $details): void
    {
        DB::table('audit_logs')->insert([
            'id' => 'audit_'.Str::random(12),
            'entity' => $entity,
            'action' => $action,
            'actor' => $actor,
            'details' => $details,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notificationSettingsForUser(string $userId): object
    {
        return DB::table('user_settings')->where('user_id', $userId)->first() ?: (object) [
            'email_notifications' => true,
            'push_notifications' => true,
            'outbreak_warnings' => true,
            'urgent_advisory' => true,
            'new_case_alert' => true,
        ];
    }

    private function notifyOfficersForAdvisory(object $farmer, string $caseId, string $cropType, string $priority): void
    {
        $officers = DB::table('officers')
            ->join('users', 'users.public_id', '=', 'officers.user_id')
            ->select('users.public_id as user_id', 'users.name', 'users.district as user_district', 'officers.region_districts')
            ->where('users.role', 'officer')
            ->get();

        foreach ($officers as $officer) {
            $settings = $this->notificationSettingsForUser($officer->user_id);
            $shouldNotify = $priority === 'urgent'
                ? (bool) $settings->urgent_advisory
                : (bool) $settings->new_case_alert;

            if (! $shouldNotify) {
                continue;
            }

            $priorityLabel = $priority === 'urgent' ? 'Urgent' : 'Normal';
            $location = implode(', ', array_values(array_filter([$farmer->upazila ?? null, $farmer->district ?? null, $farmer->division ?? null])));

            $this->createNotification(
                $officer->user_id,
                'advisory',
                $priority === 'urgent' ? 'Urgent New Case Alert' : 'New Case Alert',
                trim("{$priorityLabel} {$cropType} advisory {$caseId} submitted from {$location}."),
                ['push', 'email'],
            );
        }
    }
}
