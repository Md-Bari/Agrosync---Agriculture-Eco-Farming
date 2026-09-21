'use client';

import { useEffect, useMemo, useState } from 'react';
import {
  ArrowUpDown, CheckCircle2, Filter, Package, Search, ShieldCheck, X,
} from 'lucide-react';
import DashboardShell from '@/components/dashboard/DashboardShell';
import {
  Card, EmptyState, PageHeader, Pagination, SectionHeader, StatusBadge,
} from '@/components/dashboard/DashboardComponents';
import { ADMIN_FALLBACK_USER, ADMIN_NAV_ITEMS } from '@/components/dashboard/adminConfig';
import { useRoleUserContext } from '@/components/dashboard/useRoleUserContext';
import { adminService } from '@/services';
import type { Order, OrderStatus } from '@/types';
import { formatBDT, formatDate, formatDateTime } from '@/utils';

type SettlementFilter = 'all' | 'held' | 'ready_for_release' | 'released';
type OrderStatusFilter = 'all' | OrderStatus;
type SortOption =
  | 'ready_first'
  | 'pending_first'
  | 'newest'
  | 'oldest'
  | 'amount_high'
  | 'amount_low'
  | 'status_asc';

export default function AdminOrdersPage() {
  const { user } = useRoleUserContext({ role: 'admin', fallbackUser: ADMIN_FALLBACK_USER });
  const [orders, setOrders] = useState<Order[]>([]);
  const [settlementFilter, setSettlementFilter] = useState<SettlementFilter>('all');
  const [orderStatusFilter, setOrderStatusFilter] = useState<OrderStatusFilter>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState<SortOption>('newest');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [processingOrderId, setProcessingOrderId] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const pageSize = 10;

  const loadOrders = async () => {
    const data = await adminService.getAdminOrders();
    setOrders(data);
  };

  useEffect(() => {
    void loadOrders();
  }, []);

  const summary = useMemo(() => ({
    total: orders.length,
    held: orders.filter((order) => order.settlementStatus === 'held').length,
    ready: orders.filter((order) => order.settlementStatus === 'ready_for_release').length,
    released: orders.filter((order) => order.settlementStatus === 'released').length,
    pendingOrders: orders.filter((order) => order.status === 'pending').length,
  }), [orders]);

  // Filtered & Sorted Orders
  const filteredAndSortedOrders = useMemo(() => {
    let result = [...orders];

    // 1. Settlement Filter
    if (settlementFilter !== 'all') {
      result = result.filter((order) => order.settlementStatus === settlementFilter);
    }

    // 2. Order Lifecycle Status Filter
    if (orderStatusFilter !== 'all') {
      result = result.filter((order) => order.status === orderStatusFilter);
    }

    // 3. Search Query
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      result = result.filter((order) =>
        order.id.toLowerCase().includes(q)
        || order.farmerName.toLowerCase().includes(q)
        || order.vendorName.toLowerCase().includes(q)
        || (order.paymentGateway && order.paymentGateway.toLowerCase().includes(q))
      );
    }

    // 4. Sorting
    result.sort((a, b) => {
      switch (sortBy) {
        case 'ready_first': {
          const aReady = a.settlementStatus === 'ready_for_release' || (['delivered', 'completed'].includes(a.status) && a.paymentStatus === 'paid' && a.settlementStatus !== 'released') ? 1 : 0;
          const bReady = b.settlementStatus === 'ready_for_release' || (['delivered', 'completed'].includes(b.status) && b.paymentStatus === 'paid' && b.settlementStatus !== 'released') ? 1 : 0;
          if (bReady !== aReady) return bReady - aReady;
          return new Date(b.placedAt).getTime() - new Date(a.placedAt).getTime();
        }
        case 'pending_first': {
          const aPending = a.status === 'pending' || a.settlementStatus === 'held' ? 1 : 0;
          const bPending = b.status === 'pending' || b.settlementStatus === 'held' ? 1 : 0;
          if (bPending !== aPending) return bPending - aPending;
          return new Date(b.placedAt).getTime() - new Date(a.placedAt).getTime();
        }
        case 'amount_high':
          return b.totalAmount - a.totalAmount;
        case 'amount_low':
          return a.totalAmount - b.totalAmount;
        case 'oldest':
          return new Date(a.placedAt).getTime() - new Date(b.placedAt).getTime();
        case 'status_asc':
          return a.status.localeCompare(b.status);
        case 'newest':
        default:
          return new Date(b.placedAt).getTime() - new Date(a.placedAt).getTime();
      }
    });

    return result;
  }, [orders, settlementFilter, orderStatusFilter, searchQuery, sortBy]);

  const totalPages = Math.max(1, Math.ceil(filteredAndSortedOrders.length / pageSize));

  useEffect(() => {
    setCurrentPage(1);
  }, [settlementFilter, orderStatusFilter, searchQuery, sortBy]);

  useEffect(() => {
    setCurrentPage((prev) => Math.min(prev, totalPages));
  }, [totalPages]);

  const paginatedOrders = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return filteredAndSortedOrders.slice(start, start + pageSize);
  }, [filteredAndSortedOrders, currentPage]);

  const handleRelease = async (orderId: string) => {
    setMessage('');
    setError('');
    setProcessingOrderId(orderId);
    const result = await adminService.updateOrderSettlement(orderId, 'release');
    setProcessingOrderId(null);
    if (!result.success) {
      setError(result.message || 'Failed to release settlement.');
      return;
    }
    setMessage(`Settlement released for order ${orderId}.`);
    await loadOrders();
  };

  const hasActiveFilters = settlementFilter !== 'all' || orderStatusFilter !== 'all' || searchQuery.trim() !== '' || sortBy !== 'newest';

  const clearFilters = () => {
    setSettlementFilter('all');
    setOrderStatusFilter('all');
    setSearchQuery('');
    setSortBy('newest');
  };

  return (
    <DashboardShell navItems={ADMIN_NAV_ITEMS} role="admin" userName={user.name} userSubtitle={user.designation || 'Platform Administrator'}>
      <PageHeader title="Order Control" subtitle="Track every order, sort by approval/pending status, and release vendor settlement after delivery confirmation." />

      {message && <Card className="mb-6 border-green-200 bg-green-50"><div className="text-sm text-green-700">{message}</div></Card>}
      {error && <Card className="mb-6 border-red-200 bg-red-50"><div className="text-sm text-red-700">{error}</div></Card>}

      <Card className="mb-6">
        <SectionHeader title="Settlement Summary" subtitle="Admin-controlled payout gateway for vendors" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-2xl border border-gray-100 bg-gray-50 p-4">
            <div className="text-xs text-gray-500">Total Orders</div>
            <div className="mt-1 text-2xl font-bold text-gray-900">{summary.total}</div>
          </div>
          <div className="rounded-2xl border border-amber-100 bg-amber-50 p-4">
            <div className="text-xs text-amber-700">Held (Pending)</div>
            <div className="mt-1 text-2xl font-bold text-amber-900">{summary.held}</div>
          </div>
          <div className="rounded-2xl border border-blue-100 bg-blue-50 p-4">
            <div className="text-xs text-blue-700">Ready for Release (Approval)</div>
            <div className="mt-1 text-2xl font-bold text-blue-900">{summary.ready}</div>
          </div>
          <div className="rounded-2xl border border-green-100 bg-green-50 p-4">
            <div className="text-xs text-green-700">Released</div>
            <div className="mt-1 text-2xl font-bold text-green-900">{summary.released}</div>
          </div>
        </div>
      </Card>

      <Card className="mb-6">
        <SectionHeader
          title="Filter & Sort Orders"
          subtitle="Sort by approval, pending, dates, and amounts to manage settlements efficiently"
          action={
            hasActiveFilters ? (
              <button
                type="button"
                onClick={clearFilters}
                className="flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700"
              >
                <X className="h-3.5 w-3.5" />
                Reset Filters
              </button>
            ) : undefined
          }
        />

        <div className="space-y-4">
          {/* Top Row: Search and Sort Selector */}
          <div className="grid gap-4 md:grid-cols-[1fr_auto]">
            <div className="relative">
              <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                placeholder="Search by Order ID, farmer name, vendor name, or payment gateway..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="input-field pl-10 pr-4"
              />
            </div>

            <div className="flex items-center gap-2">
              <span className="flex items-center gap-1.5 text-sm font-medium text-gray-700 whitespace-nowrap">
                <ArrowUpDown className="h-4 w-4 text-forest" />
                Sort By:
              </span>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as SortOption)}
                className="rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-800 shadow-sm focus:border-forest focus:outline-none focus:ring-1 focus:ring-forest"
              >
                <option value="newest">📅 Newest Placed First</option>
                <option value="ready_first">⚡ Approval / Ready for Release First</option>
                <option value="pending_first">⏳ Pending Orders First</option>
                <option value="amount_high">💰 Order Amount: High to Low</option>
                <option value="amount_low">💵 Order Amount: Low to High</option>
                <option value="oldest">🕒 Oldest Placed First</option>
                <option value="status_asc">🔤 Order Status (A-Z)</option>
              </select>
            </div>
          </div>

          {/* Filter Pills: Settlement & Order Status */}
          <div className="space-y-3 pt-2">
            <div>
              <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                Settlement Payout State:
              </div>
              <div className="flex flex-wrap gap-2">
                {([
                  { key: 'all', label: 'All Settlements' },
                  { key: 'ready_for_release', label: '⚡ Ready for Release (Approval)' },
                  { key: 'held', label: '⏳ Held in Escrow' },
                  { key: 'released', label: '✅ Released Payout' },
                ] as Array<{ key: SettlementFilter; label: string }>).map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => setSettlementFilter(item.key)}
                    className={`rounded-full border px-3.5 py-1.5 text-xs font-medium transition ${
                      settlementFilter === item.key
                        ? 'border-forest bg-forest text-white'
                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                Order Delivery Status:
              </div>
              <div className="flex flex-wrap gap-2">
                {([
                  { key: 'all', label: 'All Orders' },
                  { key: 'pending', label: 'Pending' },
                  { key: 'confirmed', label: 'Confirmed' },
                  { key: 'dispatched', label: 'Dispatched' },
                  { key: 'delivered', label: 'Delivered' },
                  { key: 'completed', label: 'Completed' },
                  { key: 'cancelled', label: 'Cancelled' },
                ] as Array<{ key: OrderStatusFilter; label: string }>).map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => setOrderStatusFilter(item.key)}
                    className={`rounded-full border px-3.5 py-1.5 text-xs font-medium transition ${
                      orderStatusFilter === item.key
                        ? 'border-forest bg-forest text-white'
                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      </Card>

      {/* Showing count indicator */}
      <div className="mb-4 flex items-center justify-between text-xs text-gray-500">
        <div>
          Showing <span className="font-semibold text-gray-800">{filteredAndSortedOrders.length}</span> of {orders.length} orders
          {sortBy === 'ready_first' && ' • Sorted by Approval / Ready for Release'}
          {sortBy === 'pending_first' && ' • Sorted by Pending Orders'}
          {sortBy === 'amount_high' && ' • Sorted by Highest Amount'}
          {sortBy === 'amount_low' && ' • Sorted by Lowest Amount'}
        </div>
      </div>

      {filteredAndSortedOrders.length === 0 ? (
        <Card>
          <EmptyState
            icon={Package}
            title="No matching orders found"
            description="Try adjusting your search terms or filter selections."
          />
        </Card>
      ) : (
        <div className="space-y-4">
          {paginatedOrders.map((order) => {
            const releaseAllowed =
              ['delivered', 'completed'].includes(order.status) &&
              order.paymentStatus === 'paid' &&
              order.settlementStatus !== 'released';

            return (
              <Card key={order.id}>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-lg font-semibold text-gray-900">{order.id}</h3>
                      <StatusBadge status={order.status} />
                      <StatusBadge status={order.settlementStatus || 'held'} />
                    </div>
                    <div className="mt-2 text-sm text-gray-500">
                      Farmer <span className="font-medium text-gray-800">{order.farmerName}</span> · Vendor <span className="font-medium text-gray-800">{order.vendorName}</span>
                    </div>
                    <div className="mt-1 text-xs text-gray-400">
                      Placed {formatDateTime(order.placedAt)} · Expected {formatDate(order.estimatedDelivery)}
                    </div>
                  </div>

                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      onClick={() => handleRelease(order.id)}
                      disabled={!releaseAllowed || processingOrderId === order.id}
                      className="btn-success px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {processingOrderId === order.id ? 'Releasing...' : 'Release Vendor Payment'}
                    </button>
                  </div>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-4">
                  <div className="rounded-xl bg-gray-50 p-3">
                    <div className="text-xs text-gray-400">Order Amount</div>
                    <div className="mt-1 font-semibold text-gray-900">{formatBDT(order.totalAmount)}</div>
                  </div>
                  <div className="rounded-xl bg-gray-50 p-3">
                    <div className="text-xs text-gray-400">Payment</div>
                    <div className="mt-1 font-semibold uppercase text-gray-900">{order.paymentGateway} / {order.paymentStatus}</div>
                  </div>
                  <div className="rounded-xl bg-gray-50 p-3">
                    <div className="text-xs text-gray-400">Settlement</div>
                    <div className="mt-1 font-semibold capitalize text-gray-900">{(order.settlementStatus || 'held').replace(/_/g, ' ')}</div>
                  </div>
                  <div className="rounded-xl bg-gray-50 p-3">
                    <div className="text-xs text-gray-400">Released At</div>
                    <div className="mt-1 font-semibold text-gray-900">{order.settlementReleasedAt ? formatDateTime(order.settlementReleasedAt) : 'Not released'}</div>
                  </div>
                </div>

                {releaseAllowed ? (
                  <div className="mt-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                    <ShieldCheck className="mr-2 inline h-4 w-4" />
                    Delivery is confirmed and payment is paid. Admin can release vendor payout now.
                  </div>
                ) : order.settlementStatus === 'released' ? (
                  <div className="mt-4 rounded-xl border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-700">
                    <CheckCircle2 className="mr-2 inline h-4 w-4" />
                    Settlement already released by admin.
                  </div>
                ) : (
                  <div className="mt-4 rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    Payment is held by admin until farmer confirms delivery and payment status becomes paid.
                  </div>
                )}
              </Card>
            );
          })}
        </div>
      )}
      <Pagination currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} />
    </DashboardShell>
  );
}


