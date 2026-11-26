# T32 — Chi tiết hồ sơ, cập nhật và audit tối thiểu

## Đã làm

GET/POST `/app/ho-so/{ULID}` dùng cookie auth, kiểm tra ApplicationPolicy trước đọc dữ liệu riêng tư, không route model binding. Blade riêng không đưa chi tiết vào snapshot Livewire. Danh sách chỉ có link khi được phép; chưa mở khóa hiện nhãn, không nút mua (T37). Resource vẫn chỉ index, không global search/export.

Admin active, staff đang phụ trách, employer có grant còn hạn được đọc/sửa. Ghi chú chung cho công ty, tối đa2000 ký tự, escape HTML, hiển thị người/vai trò/thời điểm sửa. Chỉ5 trạng thái được duyệt, không tự đổi khi mở. Lưu khóa dòng, so edit_version, kiểm tra lại quyền ngay trước save;409 khi xung đột,403 khi không còn quyền, không ghi đè. Validation422 không phản chiếu/flash nội dung nhạy cảm. Portfolio HTTP(S) mở tab mới, noopener/noreferrer, không fetch/embed.

Migration000019 bổ sung version/editor và audit_logs. RecordAudit là writer duy nhất, chỉ append, không model/action/route sửa/xóa. Ba action viewed_detail/status_changed/note_updated; metadata chỉ old/new enum khi đổi trạng thái. Không lưu ghi chú/contact/tên ứng viên. IP dùng Request::ip; chưa tin proxy nào mặc định. User agent chỉ họ trình duyệt để tránh lưu PII do client chèn. Audit lỗi thì đọc503 không trả chi tiết; save transaction rollback. Log QA giữ nguyên sau dọn fixture vì append-only.

## Tiêu chí đạt và test

ApplicationDetailsTest:403 unpaid/foreign/missing/malformed/softdelete và staff ngoài phạm vi; sửa trực tiếp bị chặn; hết hạn trước save không đổi dữ liệu;409 phiên bản cũ;422 status lạ/notes dài/version sai; mỗi vai trò đọc/sửa; escape ghi chú và editor; audit đúng một dòng mỗi hành động, fail closed/rollback; no-store/noindex; không route mutate audit; forwarded IP giả bị bỏ qua; summary links/locked label và5 status thủ công. EmployerApplicationsTest tiếp tục chứng minh snapshot/SQL projection không contact kể cả đã mua.

Browser local: mở link từ danh sách đã mua, thấy contact giả/portfolio, chọn Phỏng vấn và lưu ghi chú có thẻ b. Sau lưu trạng thái đúng, thẻ hiển thị nguyên văn, có editor/vai trò/giờ Việt Nam; console không lỗi. Tài khoản/quyền/đơn/hồ sơ thử đã dọn; không đụng tài khoản admin của người dùng. Kết quả composer test/lint và CI của commit cuối ghi trong PR.

## Chưa đạt / giới hạn

T33 mới phục vụ bytes; T34 mới viewer; T35 mới rate limit/cảnh báo. T37 mới yêu cầu mua. T23 vẫn draft thiếu corpus, PDF production tắt. Chưa có cờ vi phạm T43: ApplicationRestriction::blocked là điểm tích hợp duy nhất, hiện false, test mặc định có sẵn; khi thêm cờ phải thêm ma trận thật. Không có test giả vờ cờ đã tồn tại.

## Giả định

Append-only ở tầng ứng dụng: không có endpoint/model update/delete và writer chỉ insert; tài khoản quản trị database vẫn có quyền SQL bảo trì. Audit ID/role/company là snapshot không FK cascade để giữ bằng chứng sau xóa hồ sơ/tài khoản. Proxy chỉ cấu hình IP/CIDR thực khi deploy, không wildcard. Người dùng đã duyệt tách audit tối thiểu từT35 sangT32.

## Đề xuất

T35 xem xét role DB chỉ INSERT cho audit và kiểm soát thao tác DBA/retention riêng; không tự đặt retention ởT32. T43 nối cờ vi phạm vào ApplicationRestriction; không thay đổi softdelete đã chốt.
