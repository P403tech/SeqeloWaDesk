FROM node:22-bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends git python3 make g++ \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY . .
# Pairing files live here. Mount a Railway volume at /data or a redeploy
# unlinks every phone. Override with BAILEYS_AUTH_DIR if the mount differs.
ENV BAILEYS_AUTH_DIR=/data/baileys
RUN mkdir -p /data/baileys
EXPOSE 3000
CMD ["node", "index.js"]
