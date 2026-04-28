# =============================================================================
# Trading Journal — Frontend Dockerfile (Node build + Nginx)
# =============================================================================

# --- Stage 1: Build frontend ---
FROM node:22-alpine AS build

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG VITE_API_URL=/api
ENV VITE_API_URL=${VITE_API_URL}

# VITE_ADMIN_URL must be available at build time so Vite bakes it into the
# bundle. When unset, the cross-link to the admin SPA stays hidden.
ARG VITE_ADMIN_URL=
ENV VITE_ADMIN_URL=${VITE_ADMIN_URL}

RUN npm run build

# --- Stage 2: Serve with Nginx ---
FROM nginx:alpine

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /build/dist /usr/share/nginx/html

# Railway uses PORT env var
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "envsubst '${PORT}' < /etc/nginx/conf.d/default.conf > /tmp/default.conf && mv /tmp/default.conf /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'"]
