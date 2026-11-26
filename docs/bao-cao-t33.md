# T33 — Phục vụ file riêng tư

## Đã làm

Route GET `/app/ho-so/{id}/tep/{file}/{mode}` chỉ cookie auth + signed URL, private-file CSP ưu tiên trước ngoại lệ/app. PrivateFileLinks::temporary tạo link5 phút gắn actor/hồ sơ/file/mode; link không phải quyền truy cập, mỗi request vẫn kiểm tra ApplicationPolicy và FileReadiness. Người khác cùng công ty cũng không dùng được link đã ký cho tài khoản khác.

Policy fileRecord chỉ cho file gắn đúng hồ sơ, path đúng company/job/ULID. Pending/error/infected chặn mọi vai trò. Clean cho tải gốc dù preview failed; inline cần ready. Original canonical private path, size≤10MiB, hash phải trùng sha256 đã quét. Preview chỉ path gốc hợp lệ hoặc previews/{fileID}/{originalHash}.pdf, canonical/no symlink/size limit. Lease dùng chung worker/cleanup giữ tới lúc stream xong; đang xử lý trả503 thay vì chờ vô hạn. Không đưa original filename vào header; tên file-ID trung tính.

Đọc stream64KiB, không nạp cả file vào bộ nhớ. Inline/attachment, MIME allowlist, nosniff/no-store/noindex, CSP default/script none + sandbox. Không hỗ trợ range/conditional cache; mỗi request trả full stream hoặc deny. Không proxy/CDN cache. RecordAudit dùng lại, action application.file_viewed/file_downloaded và metadata chỉ file_id; không tên ứng viên/file/contact/nội dung. Audit phải ghi được trước trả bytes. Không thêm thư viện/migration.

## Tiêu chí đạt / test

PrivateFileServingTest: paid/unpaid/signature thiếu/hết hạn/đổiid-file-mode-actor;3 trạng thái scan bị chặn cho3 vai trò; reuse link sau revoke/expiry/membership/lock; công ty khác/file không gắn; shared same-company actor khác; path traversal/filemissing/hashchanged; auditfailclosed; stream bytes exact, nosniff/no-store/noindex/private CSP cả deny; đúng1audit, MIME/disposition; clean/failed vẫn tải gốc; DOCX download và PDF inline khác bytes đúng; previewpath lạ; issuance cầnpolicy; leasebusy; authrequired. Kết quả composer test/lint và CI ở PR commit cuối.

## Chưa đạt / giới hạn

T34 mới tạo viewer và nút trên giao diện; T35 mới rate limit/cảnh báo. Link helper dùng server, chưa endpoint phát link công khai. Không thể thu hồi bytes người dùng đã tải hoặc đang stream; quyền mới được xét lại ở request kế tiếp. Worker là nguồn chứng thực preview ready; private filesystem/DB phải chỉ cho tiến trình tin cậy ghi. Original hash kiểm tra từng lượt, preview chưa có hash riêng lưu metadata. Không tuyên bố chống việc máy chủ/DB bị chiếm quyền. PDF productionT23 vẫn tắt.

## Giả định

Disk local và flock đã kiểm chứng theoT26/T27; không dùng direct storage URL/X-Sendfile. Signed link gắn người dùng mạnh hơn chỉ check company. Rootprivate không symlink; appserver/worker dùng chung root. Useragent audit chỉ họ trình duyệt, IP theo trusted proxies được cấu hình đúng. Dữ liệu test scan clean là fixture giả để kiểm tra route, không thay chứng minh AV thật ởT27.

## Đề xuất

T34 dùng PrivateFileLinks cho nút/viewer, đọc FileReadiness thông báo; không giữ URL lâu trong snapshot. Kiểm chứng PDF iframe/sandbox desktop/mobile ởT34 trước thay frame policy. T35 bổ sung rate limit, T48 kiểm chứng quyền filesystem/proxy/cache trên host thật. Nếu yêu cầu kiểm tra toàn vẹn preview trước mỗi lượt, thêm preview hash trong task riêng kèm backfill/reprocess được duyệt.
