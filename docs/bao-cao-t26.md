# T26 — Giao dịch và dọn file an toàn

## Đã làm

- Mở rộng ca lỗi metadata/stream/DB/cleanup. Giữ file khi thân transaction đã xong nhưng commit acknowledgement/callback lỗi, vì hồ sơ có thể đã commit; không xóa bytes của hồ sơ hợp lệ. Các lỗi trước đó rollback và dọn file mới, tiếp tục thử file thứ hai khi file thứ nhất xóa lỗi.
- Lease flock ngoài root dọn, được DTO giữ tới sau transaction và runner giữ trong lúc dùng staging; cleanup nonblocking, bỏ qua active. Không thêm thư viện hoặc migration, không thêm truy cập hồ sơ/file cho người dùng.
- Lệnh `candidate-files:cleanup`: dry-run mặc định, --force rõ ràng; tuổi ít nhất 24 giờ theo mtime, recheck trước unlink, giữ mọi path/preview reference. Root canonical riêng, không symlink hoặc parent traversal. Giữ metadata không gắn hồ sơ/hồ sơ xóa mềm; chỉ liệt kê metadata ID trong dry-run qua ApplicationPolicy.
- Dừng toàn kế hoạch nếu orphan >20% hoặc metadata rỗng nhưng đĩa >10 file. DB/kiểm tra lỗi abort; log run UUID, scanned/skipped/deleted/reasons kể cả lỗi, không original_name/nội dung/PII. Production force và lịch tắt mặc định; --force không bypass chốt. Không tự thêm scheduler T42.

## Tiêu chí đạt và test

`CandidateCleanupTest`: dry-run giữ mọi file; force chỉ dọn orphan; giữ file dưới 24 giờ và preview reference; boundary đúng 24 giờ; chốt ratio/empty metadata; active lease; file hồ sơ xóa mềm; symlink file/thư mục ra ngoài; mtime đổi giữa kiểm tra và xóa; DB mất kết nối và log sanitized; staging đang inspect/idle (kể cả readonly); production opt-in và root chứa `..`.

`SubmissionTransactionTest`: `preserves files if a commit acknowledgement fails after records have been committed`; `removes a newly written CV when inserting its metadata fails`; `removes a partial disk write if the storage operation throws before returning metadata`; `continues cleaning the second file when cleanup of the first file fails`; `sanitizes a database connection exception with candidate bindings and removes uncommitted files`.

Local đã chạy dry-run thật, không --force, không xóa dữ liệu local. Test xóa dùng disk fake và dữ liệu tổng hợp. Windows không có quyền tạo symlink nên ca đó được xác minh trên CI Ubuntu, không coi skip là đã test trên Windows. Bộ composer test/lint và CI cuối ghi trong PR.

Local ngày 25/11: bộ đầy đủ đạt **663 test, 2207 assertions, 1 skip symlink Windows**; sau thay đổi nhỏ cuối ở fail-closed của lease PDF, chạy lại nhóm liên quan qua `composer test`: **36 test, 108 assertions, 1 skip symlink**. `composer lint` và `git diff --check` xanh. CI chạy đầy đủ trên commit cuối và phải xác minh symlink không skip trước chốt task. Không thay UI/asset nên không chạy lại browser/build không liên quan.

## Chưa đạt và giới hạn

- Chưa review dry-run production hoặc kiểm chứng flock/ACL trên máy chủ thật; production force/lịch vẫn tắt. PHP upload_tmp_dir không bị T26 dọn vì PHP không tham gia application lease; chỉ staging ứng dụng có lease được dọn. Không đụng thư mục tạm dùng chung.
- Cleanup lỗi xóa có thể để lại orphan, được báo số lượng và chỉ được dọn sau 24 giờ khi đủ chốt. Không hứa xóa được khi filesystem/DB đang lỗi. Mất xác nhận commit chỉ mô phỏng bằng event, chưa thử mất mạng thực trên server.
- Không xóa metadata, hồ sơ, PII hoặc file còn tham chiếu; không thay retention T42/T46. Empty directory và lockfile bookkeeping không tự xóa. T23 thiếu corpus, PDF production vẫn tắt; T27 còn quét/chuyển đổi.

## Giả định

Mtime dùng UTC Unix timestamp; threshold 20% trên tổng file thường quét trong hai root, không tính symlink. “Nhiều file” khi metadata rỗng là trên 10. Root do ứng dụng sở hữu, không cho user khác thay parent directory; ứng dụng khác ghi file cùng root phải dùng lease. Disk hiện local; shared filesystem cần xác minh riêng trước bật production.

## Đề xuất

Người dùng quyết định quy tắc metadata không gắn hồ sơ sau khi xem dry-run; không tự xóa ở T26. Khi triển khai, review số liệu rồi mới bật xóa thật và lịch ở task tương ứng. Giữ các giới hạn production đã chốt.
