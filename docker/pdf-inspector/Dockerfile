FROM ubuntu:24.04@sha256:9c7f200c07e3d4a03e7249d2c16bf66a51120fb876aa2242cf31451412543289 AS probe-build
RUN apt-get update && apt-get install --no-install-recommends -y gcc libc6-dev
COPY sandbox-probe.c /tmp/sandbox-probe.c
RUN gcc -O2 -Wall -Wextra -o /tmp/sandbox-probe /tmp/sandbox-probe.c

FROM ubuntu:24.04@sha256:9c7f200c07e3d4a03e7249d2c16bf66a51120fb876aa2242cf31451412543289
RUN apt-get update \
    && apt-get install --no-install-recommends -y qpdf \
    && rm -rf /var/lib/apt/lists/* \
    && qpdf --version
COPY --from=probe-build /tmp/sandbox-probe /usr/local/bin/sandbox-probe
USER 65534:65534
ENTRYPOINT ["/usr/bin/qpdf"]
