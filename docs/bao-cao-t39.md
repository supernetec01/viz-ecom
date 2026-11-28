# Báo cáo T39 — gia hạn và thu hồi quyền theo tin

## Đã làm

Nhánh `codex/t39-access-grant-lifecycle`, từ T38, một PR riêng dạng draft. Admin có danh sách quyền và trang quản lý theo tin. Gia hạn tạo đơn pending mới chụp cấu hình hiện tại, xác nhận T38 tạo grant nối tiếp mới và previous_grant_id; giữ nguyên đơn và khoảng quyền cũ. Gift/trial0đ bắt buộc lý do, optional days/manual ends. Thu hồi cả active/future trong đúng company/job sau xem trước mã/khoảng và chọn lý do; không đổi paid orders hay tự hoàn tiền.

Migration mới000024, FK parent cùng company/job, không backfill. is_renewal/binding/khoảng đã cấp bất biến trong model save/quiet-save; revoked không bật lại. [starts,ends) dùng chung với T30, nối tại ends được phép, trùng thật bị chặn. Role kiểm lại dưới khóa; snapshot xem trước gắn actor/phạm vi/tập quyền, thay đổi trả409. RecordAudit ghi mỗi grant, PII không vào audit, lỗi rollback toàn bộ.

## Tiêu chí và test

`tests/Feature/AccessGrantLifecycleTest.php`:

- `denies the grant management list to employers and staff`; `denies direct revoke and renewal requests from nonadmins before processing inputs`; `denies the lifecycle preview to nonadmins and rejects a stale demoted admin on submit`: 403 trước xử lý dữ liệu/đọc quyền, không mutation.
- `shows only grant metadata and the affected intervals before revocation`: HTML/Livewire list và preview, không dữ liệu ứng viên, no-store.
- `renews an active grant with a new paid order and an exactly adjacent half open interval`: starts==ends cũ, trước/tại/sau ranh giới một giây, snapshot không đổi, audit có parent, đơn/grant cũ nguyên vẹn.
- `starts renewal of an expired grant at confirmation time without backdating`; `ignores a revoked last grant and creates two consecutive renewal links`: hết hạn bắt đầu now, bỏ revoked, hai lần liên tiếp tạo chuỗi.
- `refuses renewal when every grant was revoked including after creating a pending order`; `reuses one pending renewal order without changing its snapshot`: không bật lại, không trùng pending/đổi purpose.
- `requires a reason for zero amount renewal and supports gift and trial without a bank reference`; `keeps a zero amount renewal without duration empty until the admin selects an expiry`; `ignores client supplied duration price and bank on a bank renewal`.
- `revokes only current and scheduled grants for one job while keeping every paid order unchanged`: tin khác cùng công ty giữ nguyên, quyền hết hạn giữ nguyên, đúng một audit cho từng affected grant, replay preview cũ không nhân audit.
- `rejects missing or unapproved revoke reasons and never logs arbitrary contact text`; `refuses an unseen newly scheduled grant or a preview from another administrator`.
- `rolls back all revocations when the audit writer fails`: lỗi ở audit thứ hai rollback cả audit đầu và mọi trạng thái.
- `denies the very next detail inline and download request in the same employer session after real admin revocation`: gọi service thu hồi thật, không đổi phiên employer; cả detail, signed inline và download kế tiếp403, không PII/bytes.
- `blocks changing an existing interval and a cross company or backward history link`; `rejects a genuinely overlapping new purchase while preserving the existing right`; `preserves old rows through the lifecycle migration round trip and refuses losing renewal history`.

`GrantLifecycleConcurrencyTest` + `GrantLifecycleWorker` CLI-only testing/viz_ecom_test: hai tiến trình thật, quan sát lock wait PostgreSQL. Ba trường hợp: tạo pending đồng thời chỉ một đơn; confirm trước khiến preview revoke cũ409; revoke trước khiến confirm gia hạn bị từ chối renewal_missing, không tự mở quyền.

## Kết quả kiểm tra

Local Windows: composer test với PDF_SANDBOX_TESTS=1 và FILE_PROCESSING_TESTS=1 đạt **1171 passed, 1 skipped, 4400 assertions**, 274.96 giây. Một skip symlink do Windows, CI Ubuntu kiểm tra lại trường hợp này. Bộ liên quan trước vòng cuối: 301 passed/913 assertions. Composer lint (Pint + Larastan) xanh, npm run build xanh, git diff --check sạch. Không lặp kiểm tra trình duyệt các chức năng không liên quan.

Migration000024 đã áp dụng vào DB demo local bằng php artisan migrate, config:clear thành công. Không tạo thanh toán thật, không gia hạn/thu hồi quyền thật trong DB demo; mọi fixture và thao tác thử thuộc viz_ecom_test. Không đổi mật khẩu hoặc 2FA tài khoản có sẵn. CI Ubuntu của commit PR sẽ được ghi trong PR trước báo hoàn tất.

## Chưa làm / giới hạn

Không làm T38B hủy/thay thế, T40, employer tự yêu cầu gia hạn, thu hồi cả công ty vì vi phạm (T43), email, API thanh toán hoặc hoàn tiền. Nếu pending gia hạn đã tạo rồi mọi quyền bị thu hồi, giữ pending/báo lỗi; chờ T38B hoặc luồng cấp mới hợp lệ, không tự đổi đơn. PDF production vẫn tắt, T23 vẫn chờ đủ ma trận mẫu theo tài liệu trước. Không đưa CV cá nhân vào fixture/Git. Không thu lại dữ liệu đã tải trước lúc thu hồi. Các guard model không thay thế quyền DBA; không có route sửa/phục hồi grant.

## Giả định dùng

Lý do thu hồi là danh mục mã an toàn, tránh PII trong audit; không thêm ô ghi chú hoàn tiền. Giới hạn min/max thời hạn gia hạn tính từ starts mới, confirmed_at vẫn now. Pending gia hạn dùng lại nhưng không tự biến pending cấp mới thành gia hạn. Danh sách hết hạn lúc xác nhận cũng phải xem lại vì tập preview đổi.

## Đề xuất

Review PR T39 trước T40. Khi triển khai thật, cấu hình giá/ngân hàng/số ngày và đối chiếu chuyển khoản bằng người vận hành; test không chứng minh có tiền thật. T43 giữ thứ tự khóa job/order/grant khi tích hợp thu hồi do vi phạm.
