FROM rust:1.89-bookworm AS builder
WORKDIR /src
COPY Cargo.toml Cargo.lock ./
COPY src ./src
COPY migrations ./migrations
COPY public ./public
RUN cargo build --locked --release

FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*
RUN useradd --create-home --uid 10001 hfiuc
WORKDIR /app
COPY --from=builder /src/target/release/hfiuc-api /usr/local/bin/hfiuc-api
COPY --from=builder /src/public ./public
RUN printf '#!/bin/sh\nexec /usr/bin/curl -fsS http://127.0.0.1:8000/healthz >/dev/null\n' > /usr/local/bin/hfiuc-api-healthcheck \
    && chmod +x /usr/local/bin/hfiuc-api-healthcheck
USER hfiuc
ENV BIND_ADDRESS=0.0.0.0 PORT=8000 RUST_LOG=hfiuc_api=info,tower_http=info
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/hfiuc-api"]
