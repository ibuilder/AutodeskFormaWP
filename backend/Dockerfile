# Build stage: compile TypeScript with dev dependencies present.
FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY tsconfig.json ./
COPY src ./src
RUN npm run build

# Runtime stage: production dependencies and compiled output only.
FROM node:22-alpine AS runtime

ENV NODE_ENV=production
WORKDIR /app

# tini reaps zombies and forwards signals, so the graceful shutdown handler
# actually receives SIGTERM.
RUN apk add --no-cache tini

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY --from=build /app/dist ./dist

# The data directory holds encrypted tokens and job history when no database is
# configured. Owned by the unprivileged user so the container need not run as root.
RUN mkdir -p /app/data && chown -R node:node /app

USER node

EXPOSE 3000

# Readiness is checked rather than liveness, so a container with no usable
# Autodesk session is not reported healthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
	CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/ready').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

ENTRYPOINT [ "/sbin/tini", "--" ]
CMD [ "node", "dist/index.js" ]
