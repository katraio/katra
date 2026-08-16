FROM node:22-bookworm-slim

COPY docker/development-entrypoint.sh /usr/local/bin/katra-client-entrypoint

RUN chmod +x /usr/local/bin/katra-client-entrypoint

WORKDIR /app

EXPOSE 5173

ENTRYPOINT ["katra-client-entrypoint"]
