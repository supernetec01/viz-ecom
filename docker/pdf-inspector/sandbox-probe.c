#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

/* Test/attestation helper only. It never reads the uploaded document. */
int main(int argc, char **argv) {
    if (argc != 2) return 2;
    if (geteuid() == 0) return 8;
    if (!strcmp(argv[1], "lifetime")) { sleep(5); return 0; }
    if (!strcmp(argv[1], "hold")) { sleep(60); return 0; }
    if (!strcmp(argv[1], "network")) {
        int write_fd = open("/tmp/viz-write-probe", O_WRONLY | O_CREAT, 0600);
        if (write_fd >= 0) { close(write_fd); unlink("/tmp/viz-write-probe"); return 9; }
        if (errno != EROFS) return 9;
        alarm(2);
        int fd = socket(AF_INET, SOCK_STREAM, 0);
        if (fd < 0) return 3;
        struct sockaddr_in address = { .sin_family = AF_INET, .sin_port = htons(443) };
        inet_pton(AF_INET, "1.1.1.1", &address.sin_addr);
        int result = connect(fd, (struct sockaddr *)&address, sizeof(address));
        int saved = errno;
        close(fd);
        if (result == -1 && saved == ENETUNREACH) { puts("network-unreachable"); return 0; }
        return 4;
    }
    if (!strcmp(argv[1], "memory")) {
        for (int i = 0; i < 1024; i++) {
            volatile unsigned char *p = malloc(1024 * 1024);
            if (!p) return 5;
            for (int j = 0; j < 1024 * 1024; j += 4096) p[j] = 1;
        }
        return 6;
    }
    if (!strcmp(argv[1], "output")) {
        char data[8192]; memset(data, 'X', sizeof(data));
        for (int i = 0; i < 8192; i++) if (fwrite(data, 1, sizeof(data), stdout) != sizeof(data)) return 7;
        return 0;
    }
    return 2;
}
