FROM ubuntu:24.04@sha256:9c7f200c07e3d4a03e7249d2c16bf66a51120fb876aa2242cf31451412543289 AS probe-build
RUN apt-get update && apt-get install --no-install-recommends -y gcc libc6-dev
COPY processor-probe.c /tmp/processor-probe.c
RUN gcc -O2 -Wall -Wextra -o /tmp/processor-probe /tmp/processor-probe.c

FROM ubuntu:24.04@sha256:9c7f200c07e3d4a03e7249d2c16bf66a51120fb876aa2242cf31451412543289
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install --no-install-recommends -y clamav libreoffice-writer fonts-liberation ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=probe-build /tmp/processor-probe /usr/local/bin/processor-probe
USER 65534:65534
ENTRYPOINT ["/usr/local/bin/processor-probe", "lifetime"]
