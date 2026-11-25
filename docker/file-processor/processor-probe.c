#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

int main(int argc, char **argv) {
    if (argc != 2 || geteuid() == 0) return 2;
    if (!strcmp(argv[1], "lifetime")) { sleep(75); return 0; }
    if (!strcmp(argv[1], "network")) {
        int out = open("/viz-root-probe", O_WRONLY | O_CREAT, 0600);
        if (out >= 0) { close(out); unlink("/viz-root-probe"); return 3; }
        if (errno != EROFS) return 3;
        alarm(2);
        int fd = socket(AF_INET, SOCK_STREAM, 0);
        if (fd < 0) return 4;
        struct sockaddr_in address = { .sin_family = AF_INET, .sin_port = htons(443) };
        inet_pton(AF_INET, "1.1.1.1", &address.sin_addr);
        int result = connect(fd, (struct sockaddr *)&address, sizeof(address));
        int saved = errno;
        close(fd);
        if (result == -1 && saved == ENETUNREACH) { puts("network-unreachable"); return 0; }
        return 5;
    }
    return 2;
}
