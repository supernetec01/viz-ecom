# Đề xuất kiểm tra PDF cho T23 — chờ người dùng duyệt

Đối chiếu nguồn chính thức ngày 21/11/2026. **Người dùng đã duyệt phương án 1**: qpdf + JSON và runner cô lập, không thêm Composer package. Đã xây image Ubuntu/qpdf và kiểm tra local; bản triển khai ở `docs/pdf-inspector-t48.md`. Giữ phần so sánh dưới đây làm căn cứ quyết định. PR T23 vẫn draft vì còn mẫu tương thích thiếu; PDF production vẫn tắt. Không áp dụng phương án chặn mọi object stream.

## So sánh ba phương án

| Phương án | PDF hiện đại và nội dung chủ động | Giấy phép / bảo trì | Cài trên Ubuntu, chỉ sau khi duyệt | Đánh đổi |
| --- | --- | --- | --- | --- |
| **1. qpdf CLI + bộ kiểm tra cấu trúc JSON của ứng dụng — đề xuất chọn** | qpdf đưa cả object bên trong object stream ra dạng object JSON riêng, tên PDF được chuẩn hóa. Ứng dụng kiểm tra dictionary/action và tham chiếu, không grep dữ liệu PDF nén. Từ chối JavaScript, OpenAction, AA, Launch và các loại nội dung chủ động không hỗ trợ. qpdf tự nó không phải công cụ diệt mã độc hoặc bộ lọc an toàn hoàn chỉnh | Apache-2.0. Upstream có bản 12.4.2 ngày 26/09/2026, tiếp tục sửa kiểm tra cấu trúc/giới hạn tài nguyên. Ubuntu 24.04 cung cấp dòng 11.9 qua kho phân phối; không coi số phiên bản thấp hơn upstream là bằng chứng chưa có bản vá | `sudo apt update` rồi `sudo apt install qpdf`; kiểm tra `qpdf --version`, nguồn gói và bản vá. Không thêm package Composer; dùng Symfony Process đã có. Cần runner/container cô lập và giới hạn tài nguyên, không chỉ cài binary | Giữ file gốc và khả năng chọn/tìm chữ; ít thay đổi dữ liệu. Phải tự viết và test chính sách từ chối hành động dựa trên đồ thị object, xử lý tên escape, tham chiếu gián tiếp, giới hạn độ sâu/số object/kích thước JSON. Không dùng riêng `--check` để kết luận không có JavaScript |
| **2. smalot/pdfparser + bộ kiểm tra object trong PHP** | Có xử lý object/stream nén. Là thư viện trích xuất dữ liệu, không phải security sanitizer; cần chứng minh truy cập đủ object/action bằng corpus trước khi chọn. Không chỉ gọi getText(). Không bật ignoreEncryption | LGPL-3.0; README ghi **limited maintenance**, không có phát triển tính năng tích cực và không cam kết thời gian xử lý PR. Vì vậy rủi ro phụ thuộc cao hơn cho hàng rào an toàn | `composer require smalot/pdfparser` trên nhánh riêng sau khi duyệt phiên bản ổn định, cập nhật lock. Cần PHP zlib/iconv. Dù PHP thuần vẫn nên chạy trong worker CLI cô lập có hard timeout/memory, không parse upload không giới hạn ngay trong PHP-FPM | Không cần qpdf binary, nhưng vẫn cần isolation/giới hạn tiến trình. Phải audit đường đọc object/trailer mã hóa; không mặc định khả năng trích xuất chữ đồng nghĩa đáp ứng toàn bộ tiêu chí bảo mật |
| **3. qpdf gate + Ghostscript raster hóa trong môi trường cô lập** | qpdf kiểm tra và **từ chối** PDF có hành động trước; sau đó Ghostscript render trang thành ảnh để tạo bản xem trước tĩnh. Raster hóa đơn lẻ không đáp ứng yêu cầu từ chối file có JavaScript. PDF hiện đại được kiểm tra/render qua engine, lỗi thì từ chối | qpdf Apache-2.0; Ghostscript AGPL/commercial. Trang phát hành Ghostscript nêu 10.08.0 ngày 08/09/2026 và liên kết bản vá CVE. Cần rà điều kiện giấy phép khi chọn cách phân phối/vận hành, không tự kết luận toàn bộ ứng dụng phải đổi giấy phép | `sudo apt install qpdf ghostscript`, rồi chạy trong sandbox không mạng, không root, filesystem giới hạn. `-dSAFER` chỉ là một lớp, không thay sandbox OS | Thêm engine phức tạp và công việc nền, CPU/RAM/đĩa cao hơn. Bản ảnh mất chọn/tìm chữ, hyperlink và có thể ảnh hưởng chữ nhỏ/chữ ký. Không tự thay file gốc bằng ảnh trong T23; cần duyệt thay đổi sản phẩm/phạm vi nếu chọn |

Nguồn cho khả năng/giấy phép/bảo trì: [qpdf JSON](https://qpdf.readthedocs.io/en/stable/json.html), [qpdf project/license](https://github.com/qpdf/qpdf), [qpdf release notes](https://qpdf.readthedocs.io/en/stable/release-notes.html), [qpdf trên Ubuntu](https://packages.ubuntu.com/search?keywords=qpdf), [PdfParser README](https://github.com/smalot/pdfparser), [PdfParser parser source](https://github.com/smalot/pdfparser/blob/master/src/Smalot/PdfParser/Parser.php), [PdfParser encryption](https://github.com/smalot/pdfparser/blob/master/doc/Usage.md), [Ghostscript usage](https://ghostscript.readthedocs.io/en/latest/Use.html), [Ghostscript releases](https://ghostscript.com/releases/index.html), [Artifex licensing](https://artifex.com/licensing).

## PDF mã hóa — cả ba phương án

Từ chối **mọi** PDF có encryption, gồm trường hợp mở được bằng mật khẩu rỗng/chỉ giới hạn quyền. Không yêu cầu ứng viên gửi mật khẩu, không thử giải mã rồi nhận file. Thông báo: “CV PDF đang được mã hóa hoặc đặt mật khẩu. Vui lòng xuất lại bản PDF không mã hóa rồi gửi lại.”

Với qpdf, `--is-encrypted` có exit code riêng: 0 là có mã hóa, 2 là không; cần đồng thời kiểm tra cấu trúc/JSON để không coi lỗi đọc là file sạch. `--requires-password` không đủ để loại mọi file mã hóa. JSON vẫn phải được kiểm tra encryption/trailer; không chỉ trông vào chuỗi `/Encrypt` trong bytes gốc. Tham khảo [qpdf CLI](https://qpdf.readthedocs.io/en/stable/cli.html).

## Giới hạn và cách chạy an toàn đề xuất

Các mức sau là **ngân sách đề xuất, chưa benchmark**, không phải số đo hay cam kết tốc độ:

| Hướng | File 10 MB | Ngân sách xử lý đề xuất |
| --- | --- | --- |
| qpdf + JSON | Chưa đo trên corpus và máy chủ mục tiêu; dung lượng không cho biết số object/độ nén | Tổng wall time tối đa 5 giây/lần kiểm tra, 256 MiB, output JSON tối đa 16 MiB; vượt thì từ chối, không fallback grep. Điều chỉnh chỉ sau đo và xem lại |
| PHP parser | Chưa đo; có thể tạo nhiều bản dữ liệu/object trong RAM | Worker tối đa 5 giây, 256 MiB OS limit và PHP memory_limit thấp hơn; vượt thì từ chối. Không dựa vào set_time_limit đơn lẻ |
| qpdf + raster | Chưa đo; số trang, ảnh/độ phân giải quyết định nhiều hơn kích thước file | Gate như trên, render tối đa 30 giây, 512 MiB, giới hạn số trang/độ phân giải/output disk; mức này cần duyệt nếu chọn vì có thể loại CV dài |

Tất cả hướng phải: gọi lệnh bằng mảng argv, không shell; file input dùng tên/đường dẫn do server tạo, không tên upload; user riêng không root; không mạng; root filesystem readonly, chỉ mount input đọc và scratch ghi; giới hạn CPU/RAM/process/output và kill cả process tree khi quá thời gian. Không cho web user sudo tùy ý. Log chỉ loại lỗi/thời gian/kết quả, không nội dung, tên gốc, token hay output parser có thông tin CV. Sai cấu hình, binary vắng, lỗi parse, JSON sai/quá lớn, timeout/OOM đều từ chối.

Ubuntu có thể triển khai runner/container đã cấu hình sẵn với network=none, hoặc service systemd phù hợp; MemoryMax và giới hạn runtime phải được kiểm chứng bằng test, không coi Symfony Process timeout là giới hạn RAM/no-network. Windows local cần runner tương đương được kiểm tra hoặc giữ PDF bị chặn; không tự mở fallback ít an toàn. Đây là phần cần chốt và triển khai cùng phương án được duyệt. [systemd resource control](https://freedesktop.org/software/systemd/man/latest/systemd.resource-control.html).

## Bộ test và benchmark trước khi mở PDF

1. Corpus PDF thật xuất từ Word, Google Docs, in trình duyệt, Canva và điện thoại; có/không object stream; ghi nguồn/phiên bản/cách xuất, chỉ dữ liệu giả không PII. Không gắn nhãn nguồn giả chỉ bằng sửa Producer. Hiện chưa có đủ corpus này; chưa đánh dấu tương thích đạt.
2. Tạo file PDF thực hợp lệ chứa JavaScript vô hại `app.alert(...)`, một bản dictionary thông thường và một bản action trong object stream nén. Kiểm tra nhận diện qua đồ thị object, gồm tên escape và tham chiếu gián tiếp, OpenAction/AA/Launch. Không mở những file này bằng trình xem thực thi JavaScript.
3. PDF mã hóa cần mật khẩu và mật khẩu rỗng đều bị từ chối; PDF lỗi/truncated, input giả đuôi, object/decompression bomb, thiếu công cụ, timeout/RAM/output quá mức đều bị từ chối; không ghi/file DB còn sót.
4. Chạy nhiều lượt với file 10 MB và nhiều mức số trang/object; báo median/p95, peak RSS, wall time trên cấu hình Ubuntu cụ thể, kể cả dưới tải đồng thời. Chưa cài công cụ nên **chưa có số đo** cho bất kỳ phương án nào.
5. ClamAV T27 là lớp bổ sung sau này, không thay thế kiểm tra PDF hiện đại hoặc cho phép bỏ qua timeout/lỗi. File vẫn pending cho tới luồng quét/phân quyền hoàn tất; không cung cấp URL trực tiếp.

## Quyết định đang xin duyệt

Đề xuất **phương án 1**: qpdf + kiểm tra JSON cấu trúc, cài qpdf và xây runner cô lập có giới hạn; không thêm PHP parser hoặc Ghostscript ở T23. Chỉ triển khai/cài sau khi người dùng duyệt rõ công cụ và cách chạy. Trong thời gian chờ, tiếp tục DOCX/kích thước/MIME/UUID/private storage, giữ PR draft, PDF chặn mặc định và không đánh dấu T23 hoàn tất.
