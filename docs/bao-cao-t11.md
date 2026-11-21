# T11 — Quản lý tin tuyển dụng

Trạng thái: đã đạt kiểm thử tại máy và kiểm tra trình duyệt sau khi sửa CSP theo quyết định người dùng ngày 21/11/2026. Nhánh `task/t11-job-management`, PR #12, nối tiếp T10; không đưa mã T12/T13 vào PR và không merge main.

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
- `ContentSecurityPolicyTest`: 12 test, kiểm tra nonce mới theo yêu cầu/khớp mọi script, không inline script, đúng runtime, không rò CSP sang công khai/đăng nhập, sandbox file riêng tư và hồi quy phiên qua hai POST Livewire với cookie mã hóa.
- Toàn bộ T11: `composer test` **198 passed, 730 assertions**; `composer lint` **Pint/Larastan xanh**; `composer validate --strict` xanh.
- Migration paused đã áp dụng thành công lên database phát triển. Dữ liệu/tài khoản giả kiểm tra trình duyệt đã được dọn; tài khoản admin demo của người dùng không bị sửa.

## Kiểm tra trình duyệt sau sửa CSP

Đã đăng nhập bằng tài khoản giả có 2FA trên trình duyệt tại localhost và thực hiện:

1. Mở menu Thao tác và hộp thoại xác nhận.
2. Tạm dừng tin đang đăng, bảng hiển thị Tạm dừng và giữ ngày hết hạn.
3. Mở Tạo mới, chọn công ty/nhóm/địa điểm, nhập ba trường rich text và lưu được bản nháp.
4. Sửa tiêu đề, lưu, mở Xem trước và thấy đúng nội dung đã sửa.
5. Nhân bản tin đang tạm dừng, có bản nháp mới, ngày đăng/hết hạn không sao chép.
6. Console sau lần nạp bản sửa không có error/warning, bao gồm không còn lỗi CSP. Dữ liệu giả đã dọn, admin demo không thay đổi.

Ảnh minh chứng local: `.runtime/t11-csp-verified.jpg` (menu, bản nháp, tin paused), `.runtime/t11-csp-preview.jpg` (nội dung đã sửa). Console đã lọc từ thời điểm sửa runtime: `.runtime/t11-csp-console.json` là `[]`. Các artifact này được Git bỏ qua.

## Chưa đạt / ngoài phạm vi

Không còn điểm chặn trong phạm vi T11. Chưa có endpoint phục vụ CV/portfolio; mới cung cấp alias `csp.private-file` và test ưu tiên sandbox để T33 gắn vào endpoint thật.

Chưa chạy test form ứng tuyển khi paused hoặc thứ tự gói đẩy tin sau tiếp tục: các bảng/luồng tương ứng chưa có tại T11. Đã ghi yêu cầu vào mục 3.3 cho T18/T20/T24/T40/T41/T42 và không tạo trước các task đó.

## Giả định

- T11 chỉ tạo bản nháp; nút đăng/quét nội dung và duyệt thuộc T12/T13. Đường draft → published trong model dành cho service quét T12, không nhận từ form T11.
- Chỉ sửa bản nháp; pending/paused/expired có thể về draft theo luồng đã duyệt. Closed không mở lại. Công ty của tin cố định sau tạo.
- Không cần dependency hoặc migration dữ liệu nào ngoài migration mới cho trạng thái paused.
- Ngoại lệ CSP dùng đúng phạm vi đã duyệt. Nonce Laravel áp dụng trước render; footer panel chèn runtime chuẩn trước khi phục hồi cấu hình. Stack cookie/session không chạy lại như persistent middleware trong Livewire; middleware xác thực/2FA vẫn persistent, kiểm tra tài khoản hoạt động vẫn có trên web/panel. Test HTTP cookie mã hóa bảo vệ lỗi mất phiên đã phát hiện khi xác minh UI.

## Đề xuất / bước tiếp

Bỏ ngoại lệ khi Filament đã hỗ trợ runtime CSP-safe và kiểm tra lại đầy đủ thao tác; mỗi lần đổi CSP phải sửa test. Khi làm T33, gắn profile file riêng tư cho mọi endpoint xem/tải và kiểm tra file thật với sandbox. Dừng sau báo cáo để người dùng xem lại; không tự làm T13.

Để chạy kiểm tra mã trên máy hiện tại:

```powershell
cd D:\AI\Viz-Ecom
$env:Path = "$PWD\.tools\php;$env:Path"
php .tools/composer.phar test
php .tools/composer.phar lint
```
