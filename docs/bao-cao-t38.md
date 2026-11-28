# T38 — admin xác nhận thanh toán và cấp quyền

## Đã làm

Admin active có danh sách/form GET/POST `/app/xac-nhan-thanh-toan/{id}`. Staff/employer403, policy đọc lại role/status. Form CSRF native, giờVN/no-store/noindex, không applications/liên hệ/file ứng viên. Không tin scope/price/actor client. starts_at server, không lùi ngày.

Migration mới000023: duration_days nullable, duration_max_days cho CHECK cấu hình, payment_bank_key, partial unique paid bank/account/normalized ref, audit reason_code. Không sửa migration cũ/backfill thời hạn cũ. Chụp ACCESS_DURATION_DAYS khi tạo, thiếu config chặn; employer không sửa được. Đơn cũnull nhập ends tay. Gợi ý +Nngày tới23:59:59VN, min24h/ngày vàmax hết ngày cuốiVN; override có audit. Form qua nửa đêm chưa sửa tính lại lúc xác nhận, không ghi nhầm override.

ConfirmAccessOrder khóa actor/job/order/company/grants, cùng thứ tựT37. Bank ref bỏ whitespace/uppercase, tiền nhận phải bằng đơn. Gift/trial0đ cầnreason, không cần mã. Paid+grant+hai audit cùng transaction. Replay trả cũ, không đổi ngày/phục hồi revoked. Scope thiếu/paid thiếu grant/vi phạm seam/deleted/ineligible/overlap dừng+báo lỗi+audit, không tự sửa. Audit lỗi failclosed/rollback, kể cả audit thứhai/override.

Tiền lệch thấp/cao:409 hiển thị cảhai số tiền, giữ pending/no grant; order.confirm_rejected reason_code amount_mismatch metadata đúng order_id/order_amount/received_amount. Bank0/âm/không phải số422. Không hủy/tạo mới (T38B). RecordAudit writer duy nhất, success metadataIDs/thời hạn, không mã giao dịch/ngân hàng/PII.

## Tiêu chí đạt và test

ConfirmAccessPaymentsTest +PaymentConfirmationConcurrencyTest cuối:81 ca/327 assertions xanh. Gồm403 trước mã, ngàyVN/starts server, replay; hai tiến trình admin thật quan sát wait_event_type=Lock, cùng đơn một grant, khác đơn cùng bankref một paid/một pending; casing/space/Unicode vàrawSQL unique; gift/trial; tiền ít/nhiều/non-number/0/âm; exact rejection metadata; rollback khi từng audit lỗi; scope/paid thiếu grant/cancelled/type/deleted/vi phạm/overlap; legacynull/configsnapshot/forgeddays/configchange/SQLbounds, ngày23:30/nửa đêm/form qua nửa đêm, min/max/calendar/minute-only datetime-local, override/HMAC/starts; mở hồ sơ cũ qua ApplicationPolicy, HTML/Livewire không PII và giảmạo scope/actor. Hai ca cuối kiểm tra paid cũ có snapshot/ref (kể cả Unicode whitespace) được đưa vào unique mà không sửa dữ liệu tài chính gốc, và hiển thị starts/ends/confirmed_at giờVN sau xác nhận.

Nhóm liên quan285 test/935 assertions và lint xanh trước các test UI cuối. Bộ tổng local trước hai ca cuối:1130 passed/4204 assertions, một ca symlink Windows bỏ qua có từ trước,262,39 giây, sandbox bật. Bộ cuối81 ca và lint/npm build xanh. CI trên đúng commit cuối ghi trong PR. Không chạy tay lại chức năng khác không liên quan; composer test/lint tổng là kiểm tra bắt buộc. Ba test rollback điều chỉnh thứ tự migration con000023, không sửa migration cũ.

## Chưa đạt và giới hạn

Chưa chuyển khoản thật, email, thiết bị vật lý hoặc production; UI kiểm tra qua HTTP/nativeform/Livewire render. Chưa có UI tạo gift/trial mới, chỉ xác nhận đơn có sẵn. T38B mới hủy/thay thế, T39 mới gia hạn/thu hồi. T43 mới cờ thật; helperfalse hiện có test seam chặn. Không tự bật PDF production hoặc ghép tài chính legacy.

Ngân hàng định danh bằng tên/account snapshot đãnormalize; alias khác cần đối chiếu lịch sử, không có API xác thực ngân hàng. Migration chỉ dẫn xuất key khi paid cũ có đủ snapshot/ref; không tự suy ra từ config mới khi thiếu dữ liệu, không đổi tài chính gốc. Trùng giao dịch lịch sử dừng migration để rà soát. Khi đổi global min/max, duration cũ không đổi; admin có thể phải đổi ends để trong giới hạn hiện hành, có audit.

## Giả định

Ba quyết định T38 và bổ sung duration/T38B đã duyệt. Cùng PostgreSQL, migration trước mã, VND nguyên. Companyactive hiện là chưa xóa/chưa ApplicationRestriction chặn, không tự dùng verified làm cờ vi phạm. Tiền thực nhận do admin đối chiếu/nhập, phần mềm chưa đọc sao kê.

## Đề xuất

T38B xử lý thay thế đơn qua workflow được duyệt, không sửa tài chính cũ. T39 giữ lockorder/audit cùng transaction. T43 nối flags thật; T48 kiểm tra snapshot ngân hàng/mã và paid legacy, nhiều instance/thiết bị vàrestore. Giữ định danh ngân hàng nhất quán.
