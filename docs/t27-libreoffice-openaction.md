# T27 — PDF LibreOffice có OpenAction thụ động

## Bằng chứng local

DOCX tổng hợp sạch từ `CvFixtures` được LibreOffice trong container non-root/read-only, không mạng (TCP ENETUNREACH đã kiểm chứng), tmpfs có hạn mức, profile UUID riêng chuyển thành PDF 9688 byte. Bộ `InspectPdf` T23 từ chối `active_content`. JSON qpdf cho catalog `/OpenAction: ["1 0 R", "/XYZ", null, null, 0]`: đích trang/zoom nội bộ, không phải JavaScript. Đây chỉ là fixture, không phải CV người dùng; file gốc không đổi.

## Quyết định và kết quả đã triển khai

Người dùng đã duyệt ngoại lệ hẹp, ưu tiên cấu hình xuất tương đương. **Đã tìm được `InitialPage=0` nên không triển khai bộ xóa/chuẩn hóa OpenAction.** Phần đề xuất ban đầu bên dưới chỉ là lịch sử, không phải đường xử lý đang chạy.

`DocumentSandbox::exportOptions()` đặt InitialPage=0, tắt form/bookmark/note. Bản LibreOffice hiện tại không sinh OpenAction với cấu hình này. Chỉ áp dụng lúc xuất DOCX trong sandbox, không sửa DOCX hay PDF upload. Preview vẫn qua đúng InspectPdf T23 và ClamAV; bất kỳ OpenAction bất ngờ nào cũng bị từ chối, không có fallback xóa action. Không có thao tác bỏ action nên không có log đếm action bị bỏ.

`DocumentSandboxTest` chạy cấu hình mặc định và cấu hình mới thật: bản mặc định bị qpdf chặn, bản mới được nhận, cùng một trang và hash content stream sau giải nén. DOCX gốc không đổi. QA local dùng pypdf/Poppler có sẵn: văn bản bằng nhau, hash PNG trang đầu bằng nhau và đã mở kiểm tra tiếng Việt. Không thêm các công cụ QA vào dependency website.

`FileProcessingTest` kiểm tra pipeline LibreOffice + qpdf thật, AV giả; từng biến thể XYZ, JavaScript, URI, Launch, GoToR, GoToE, SubmitForm và đích ngoài bị chặn ở cả PDF upload và preview. Denylist T23 không đổi. Production vẫn cần kiểm chứng trên máy chủ và cập nhật bản vá.

## Lịch sử đề xuất (không cần triển khai)

Chuẩn hóa **chỉ PDF vừa do LibreOffice tạo để làm preview của DOCX**: qpdf đọc JSON; chỉ cho phép bỏ catalog OpenAction nếu nó là mảng đích trang nội bộ dạng `/XYZ`, phần tử trang là tham chiếu tới object `/Type /Page` có thật, tọa độ/zoom chỉ số hữu hạn hoặc null. Không bỏ OpenAction dạng dictionary/indirect action, script hay marker khác. Gặp dạng khác thì từ chối và cờ xem tay. Không nhận file PDF do ứng viên tải lên vào bước chuẩn hóa này.

qpdf ghi ra bản preview mới trong cùng sandbox có giới hạn tài nguyên; PDF sau chuẩn hóa phải qua **đúng InspectPdf T23 không sửa denylist**, rồi quét ClamAV trước ready. Bản tạm bị hủy. DOCX gốc và PDF gốc ứng viên không bị sửa; PDF gốc có OpenAction vẫn bị chặn. Test chứng minh chỉ biến thể thụ động của preview được chuẩn hóa và mọi dạng action khác vẫn thất bại.

Phương án còn lại: giữ preview_status failed/needs review khi LibreOffice tạo OpenAction; scan_status clean vẫn cho tải gốc theo chốt T27, nhưng tiêu chí tạo preview DOCX sạch chưa đạt.

Không triển khai chuẩn hóa hậu kỳ vì đã có cấu hình xuất tương đương theo ưu tiên của người dùng.
