# =============================================================================
# Trading Journal — Admin SPA Dockerfile (Node build + Nginx serve)
# =============================================================================
# Mirror of frontend/Dockerfile. The admin SPA hits the same /api endpoints
# as the user SPA but is served on its own domain (admin.2a-trading-tools.com /
# test-admin.2a-trading-tools.com) for a clean separation of concerns.

FROM node:22-alpine AS build

WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm ci || npm install

COPY . .

ARG VITE_API_URL=/api
ENV VITE_API_URL=${VITE_API_URL}

RUN npm run build

FROM nginx:alpine

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /build/dist /usr/share/nginx/html

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "envsubst '${PORT}' < /etc/nginx/conf.d/default.conf > /tmp/default.conf && mv /tmp/default.conf /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'"]
