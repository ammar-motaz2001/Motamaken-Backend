# Postgres 16 (same Alpine base as before, so the data directory stays compatible) with the pgvector extension,
# used by the AI matching (embeddings table). Nothing else changes.
FROM postgres:16-alpine

ARG PGVECTOR_VERSION=v0.8.0

# with_llvm=no skips the optional JIT bitcode, so no clang toolchain is needed.
RUN apk add --no-cache --virtual .build git build-base \
    && git clone --depth 1 --branch ${PGVECTOR_VERSION} https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && make -C /tmp/pgvector OPTFLAGS="" with_llvm=no \
    && make -C /tmp/pgvector install with_llvm=no \
    && rm -rf /tmp/pgvector \
    && apk del .build
