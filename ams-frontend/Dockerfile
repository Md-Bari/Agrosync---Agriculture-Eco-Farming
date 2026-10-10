# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------
FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# ---------------------------------------------------------------------------
# Build. NEXT_PUBLIC_* values are inlined into the browser bundle at build time,
# so they must be the URLs the user's browser can reach (not Docker service names).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS build
WORKDIR /app

ARG NEXT_PUBLIC_API_URL=http://localhost:8000
ARG NEXT_PUBLIC_APP_URL=http://localhost:3000
ARG NEXT_PUBLIC_APP_NAME=AgriculMS
ARG NEXT_PUBLIC_ENABLE_MOCK_API=false
ARG NEXT_PUBLIC_ENABLE_CHATBOT=true
ARG NEXT_PUBLIC_ENABLE_VIDEO_CONSULT=false

ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_APP_URL=$NEXT_PUBLIC_APP_URL \
    NEXT_PUBLIC_APP_NAME=$NEXT_PUBLIC_APP_NAME \
    NEXT_PUBLIC_ENABLE_MOCK_API=$NEXT_PUBLIC_ENABLE_MOCK_API \
    NEXT_PUBLIC_ENABLE_CHATBOT=$NEXT_PUBLIC_ENABLE_CHATBOT \
    NEXT_PUBLIC_ENABLE_VIDEO_CONSULT=$NEXT_PUBLIC_ENABLE_VIDEO_CONSULT \
    NEXT_OUTPUT_STANDALONE=true \
    NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Runtime: only the standalone server and static assets, as a non-root user
# ---------------------------------------------------------------------------
FROM node:20-alpine AS runtime
WORKDIR /app

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static

USER node
EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD wget -qO- http://127.0.0.1:3000/ > /dev/null || exit 1

CMD ["node", "server.js"]
