# Encrypted Word fixture

`encrypted-standard.docx` is the public upstream `tests/inputs/ecma376standard_password.docx` from `nolze/msoffcrypto-tool`, pinned at commit `bdb71ea5dfbdee2f3cd86f8852e74be7a6f15667`.

Source: https://github.com/nolze/msoffcrypto-tool/blob/bdb71ea5dfbdee2f3cd86f8852e74be7a6f15667/tests/inputs/ecma376standard_password.docx

SHA-256: `d265dcf02f7d552486229b8c67a627ef3752fe6802b02fd2d2d485fcfbbac5de`, 7168 bytes. MIT license is included as `LICENSE.txt`. This is an encrypted test document, not applicant data. T27 never decrypts or opens it with Office; it verifies permanent rejection before scanning/conversion, with no retry. No msoffcrypto library is installed by this project.
