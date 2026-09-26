# Web público: build de produção do React + Nginx (serve o SPA e encaminha /api ao PHP-FPM).
# Build a partir da raiz do repositório:  docker build -f docker/nginx/Dockerfile .

# 1) Build do frontend. Vite 8 exige Node ^20.19 || >=22.12; npm ci usa o package-lock.json (reproduzível).
FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

# 2) Nginx sem root (usuário nginx, porta 8080).
FROM nginxinc/nginx-unprivileged:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/dist /usr/share/nginx/html
EXPOSE 8080
