# T11 — Quản lý tin tuyển dụng

Trạng thái: đang làm, chưa đánh dấu hoàn tất. Nhánh `task/t11-job-management` nối tiếp T10; không làm T12 và không merge main.

## Đã làm

- Filament `/app/tin-tuyen-dung`: danh sách, tìm kiếm, lọc trạng thái, tạo/sửa bản nháp, xem trước, nhân bản và các thao tác tạm dừng/tiếp tục/đóng/chuyển về nháp theo policy.
- `JobPolicy`, `Job::visibleTo` và `ManageJobs` kiểm tra lại vai trò, trạng thái tài khoản và phân công công ty ở server. URL công ty khác không tìm thấy; các service/policy từ chối quyền không hợp lệ. Không cho chuyển công ty của tin đã tạo hoặc nhận `status`, `created_by`, `published_at` từ form.
- Theo xác nhận ngày 21/11/2026: thêm paused bằng migration mới; chỉ sửa nội dung trong draft. Tiếp tục trước hạn giữ ngày đăng/hết hạn; tại hoặc sau hạn chuyển expired. Closed là trạng thái cuối, muốn dùng lại nội dung thì nhân bản thành tin mới.
- Nhân bản chỉ lấy nội dung form, đặt lại draft, bỏ ngày đăng/hết hạn, sinh slug mới và không sao chép quan hệ của tin gốc (bao gồm gói đẩy tin khi có ở T40).
- Symfony HtmlSanitizer có sẵn trong dependency, đã được duyệt thay HTMLPurifier. Lọc trước lưu và khi xem trước, bỏ script/sự kiện/style/nội dung nhúng, chỉ giữ định dạng cơ bản và liên kết HTTP/HTTPS. Không cho upload file qua rich editor. Giới hạn 50.000 ký tự/trường, tính đúng UTF-8 tiếng Việt.
- Ghi quy tắc paused vào mục 3.3, T10 và T11; chỉ rõ các test phụ thuộc task công khai/ứng tuyển/gói/T42.

## Tiêu chí đạt và test

- `JobAuthorizationTest`: 14 trường hợp từ chối 403 cho view/update/replicate/pause/resume/close/redraft đối với công ty khác, employer và user bị khóa. Các test từ chối được chạy trước khi viết policy.
- `JobDataTest`: 62 test, có đầy đủ 30 cặp trạng thái khác nhau của sáu trạng thái, tạo tin/ràng buộc dữ liệu/slug/UTC và bảo toàn hàng đợi. Cập nhật ma trận theo quyết định mới, không sửa migration cũ.
- `JobManagementTest`: 22 test, gồm tạo/sửa theo vai trò, 403/404, thu hồi phân công khi trang còn mở, chặn đổi công ty, validation form, HTML/XSS ở ba trường và dữ liệu cũ, nhân bản, paused/resume, chặn sửa nội dung và thời gian, ranh giới đúng hạn/quá hạn, UTF-8.
- Toàn bộ: `composer test` **186 passed, 653 assertions**; `composer lint` **Pint/Larastan xanh**; `composer validate --strict` xanh.
- Migration paused đã áp dụng thành công lên database phát triển. Tài khoản admin demo của người dùng không bị sửa.

## Chưa đạt / điểm đang chờ

Kiểm tra trình duyệt thật phát hiện runtime CSP của Livewire không thực thi được một số biểu thức Filament sinh ra: `JSON.parse(...)` và `if ($event.button === 0) toggle($event)`. Vì vậy menu/nút thao tác chưa dùng được trên trình duyệt, dù các test PHP/Livewire đều xanh. Chưa coi T11 là hoàn tất.

Đã hỏi người dùng về phương án dùng runtime Livewire chuẩn và cho phép `unsafe-eval` chỉ trên tài liệu HTML quản trị `/app`, giữ CSP công khai hiện tại. Chưa nhận xác nhận nên chưa áp dụng thay đổi bảo mật này. Không sửa dependency/vendor và không thêm thư viện.

Chưa chạy test form ứng tuyển khi paused hoặc thứ tự gói đẩy tin sau tiếp tục: các bảng/luồng tương ứng chưa có tại T11. Đã ghi yêu cầu vào mục 3.3 cho T18/T20/T24/T40/T41/T42 và không tạo trước các task đó.

## Giả định

- T11 chỉ tạo bản nháp; nút đăng/quét nội dung và duyệt thuộc T12/T13. Đường draft → published trong model dành cho service quét T12, không nhận từ form T11.
- Chỉ sửa bản nháp; pending/paused/expired có thể về draft theo luồng đã duyệt. Closed không mở lại. Công ty của tin cố định sau tạo.
- Không cần dependency hoặc migration dữ liệu nào ngoài migration mới cho trạng thái paused.

## Đề xuất / bước tiếp

Chờ quyết định CSP, hoàn tất tương tác trên trình duyệt, dọn dữ liệu giả của phiên kiểm tra, kiểm tra CI và cập nhật báo cáo. Chỉ sau đó mới đánh dấu T11 xong và dừng chờ xem lại.

Để chạy kiểm tra mã trên máy hiện tại:

```powershell
cd D:\AI\Viz-Ecom
$env:Path = "$PWD\.tools\php;$env:Path"
php .tools/composer.phar test
php .tools/composer.phar lint
```
