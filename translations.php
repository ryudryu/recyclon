<?php

/**
 * Shared, no-cost UI translations. Add page-specific text here as each page
 * is translated, then render it with t('key').
 */
function currentLanguage(): string
{
    $language = (string) ($_SESSION['language'] ?? 'en');
    return in_array($language, ['en', 'ms', 'zh'], true) ? $language : 'en';
}

function t(string $key, ?string $fallback = null): string
{
    $translations = [
        'en' => [
            'profile' => 'Profile', 'logout' => 'Logout', 'company' => 'Company',
            'home' => 'Home', 'dashboard' => 'Dashboard', 'bookings' => 'Bookings',
            'new_booking' => 'New Booking', 'track_pickup' => 'Track Pickup',
            'information' => 'Information', 'live_pricing' => 'Live Pricing',
            'account' => 'Account', 'my_profile' => 'My Profile', 'get_in_touch' => 'Get in Touch',
            'whatsapp_contact' => 'WhatsApp / Contact Us',
            'copyright' => '© 2026 Recyclon. All rights reserved.',
            'waste_management_system' => 'Waste Management System', 'final_year_project' => 'Final Year Project',
            'booking_records' => 'Booking Records', 'matching_records' => 'matching records',
            'all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
            'select_customer' => 'Select Customer', 'search_name_ic' => 'Search by name or IC...',
            'select_customer_option' => '-- Select Customer --', 'select_pending_booking' => 'Select Pending Booking',
            'select_pending_booking_option' => '-- Select Pending Booking --', 'sort_by' => 'Sort By',
            'status_earliest' => 'Status → Earliest Date', 'status_latest' => 'Status → Latest Date',
            'date_status_earliest' => 'Earliest Date → Status', 'date_status_latest' => 'Latest Date → Status',
            'newest_booking' => 'Newest Booking', 'oldest_booking' => 'Oldest Booking',
            'booking_id' => 'Booking ID', 'customer' => 'Customer', 'category' => 'Category', 'weight' => 'Weight',
            'value' => 'Value', 'date_time' => 'Date & Time', 'pickup_date' => 'Pickup Date', 'booking_date' => 'Booking Date', 'driver' => 'Driver', 'status' => 'Status',
            'action' => 'Action', 'calculate' => 'Calculate', 'receipt' => 'Receipt', 'edit' => 'Edit', 'save' => 'Save',
            'calculate_now' => 'Calculate now', 'view_receipt' => 'View Receipt', 'no_bookings' => 'No bookings found',
            'previous' => 'Previous', 'next' => 'Next', 'jobs' => 'jobs', 'choose_lorry' => 'Choose lorry',
            'select_lorry' => '▼ Select lorry ▼', 'assign_pickup' => 'Assign for pickup',
        ],
        'ms' => [
            'profile' => 'Profil', 'logout' => 'Log Keluar', 'company' => 'Syarikat',
            'home' => 'Utama', 'dashboard' => 'Papan Pemuka', 'bookings' => 'Tempahan',
            'new_booking' => 'Tempahan Baharu', 'track_pickup' => 'Jejak Kutipan',
            'information' => 'Maklumat', 'live_pricing' => 'Harga Semasa',
            'account' => 'Akaun', 'my_profile' => 'Profil Saya', 'get_in_touch' => 'Hubungi Kami',
            'whatsapp_contact' => 'WhatsApp / Hubungi Kami',
            'copyright' => '© 2026 Recyclon. Hak cipta terpelihara.',
            'waste_management_system' => 'Sistem Pengurusan Sisa', 'final_year_project' => 'Projek Tahun Akhir',
            'booking_records' => 'Rekod Tempahan', 'matching_records' => 'rekod sepadan',
            'all' => 'Semua', 'pending' => 'Menunggu', 'confirmed' => 'Disahkan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
            'select_customer' => 'Pilih Pelanggan', 'search_name_ic' => 'Cari mengikut nama atau IC...',
            'select_customer_option' => '-- Pilih Pelanggan --', 'select_pending_booking' => 'Pilih Tempahan Menunggu',
            'select_pending_booking_option' => '-- Pilih Tempahan Menunggu --', 'sort_by' => 'Susun Mengikut',
            'status_earliest' => 'Status → Tarikh Paling Awal', 'status_latest' => 'Status → Tarikh Terkini',
            'date_status_earliest' => 'Tarikh Paling Awal → Status', 'date_status_latest' => 'Tarikh Terkini → Status',
            'newest_booking' => 'Tempahan Terkini', 'oldest_booking' => 'Tempahan Terdahulu',
            'booking_id' => 'ID Tempahan', 'customer' => 'Pelanggan', 'category' => 'Kategori', 'weight' => 'Berat',
            'value' => 'Nilai', 'date_time' => 'Tarikh & Masa', 'pickup_date' => 'Tarikh Kutipan', 'booking_date' => 'Tarikh Tempahan', 'driver' => 'Pemandu', 'status' => 'Status',
            'action' => 'Tindakan', 'calculate' => 'Kira', 'receipt' => 'Resit', 'edit' => 'Sunting', 'save' => 'Simpan',
            'calculate_now' => 'Kira sekarang', 'view_receipt' => 'Lihat Resit', 'no_bookings' => 'Tiada tempahan ditemui',
            'previous' => 'Sebelum', 'next' => 'Seterusnya', 'jobs' => 'kerja', 'choose_lorry' => 'Pilih lori',
            'select_lorry' => '▼ Pilih lori ▼', 'assign_pickup' => 'Tugaskan untuk kutipan',
        ],
        'zh' => [
            'pickup_date' => '收集日期', 'booking_date' => '预约日期', 'save' => '保存',
            'profile' => '个人资料', 'logout' => '登出', 'company' => '公司',
            'home' => '首页', 'dashboard' => '仪表板', 'bookings' => '预约',
            'new_booking' => '新预约', 'track_pickup' => '追踪收集',
            'information' => '信息', 'live_pricing' => '实时价格',
            'account' => '账户', 'my_profile' => '我的个人资料', 'get_in_touch' => '联系我们',
            'whatsapp_contact' => 'WhatsApp / 联系我们',
            'copyright' => '© 2026 Recyclon。版权所有。',
            'waste_management_system' => '废物管理系统', 'final_year_project' => '毕业项目',
            'booking_records' => '预约记录', 'matching_records' => '条匹配记录',
            'all' => '全部', 'pending' => '待处理', 'confirmed' => '已确认', 'completed' => '已完成', 'cancelled' => '已取消',
            'select_customer' => '选择客户', 'search_name_ic' => '按姓名或身份证搜索...',
            'select_customer_option' => '-- 选择客户 --', 'select_pending_booking' => '选择待处理预约',
            'select_pending_booking_option' => '-- 选择待处理预约 --', 'sort_by' => '排序方式',
            'status_earliest' => '状态 → 最早日期', 'status_latest' => '状态 → 最新日期',
            'date_status_earliest' => '最早日期 → 状态', 'date_status_latest' => '最新日期 → 状态',
            'newest_booking' => '最新预约', 'oldest_booking' => '最早预约',
            'booking_id' => '预约编号', 'customer' => '客户', 'category' => '类别', 'weight' => '重量',
            'value' => '价值', 'date_time' => '日期和时间', 'driver' => '司机', 'status' => '状态',
            'action' => '操作', 'calculate' => '计算', 'receipt' => '收据', 'edit' => '编辑',
            'calculate_now' => '立即计算', 'view_receipt' => '查看收据', 'no_bookings' => '未找到预约',
            'previous' => '上一页', 'next' => '下一页', 'jobs' => '项', 'choose_lorry' => '选择卡车',
            'select_lorry' => '▼ 选择卡车 ▼', 'assign_pickup' => '分配收集任务',
        ],
    ];

    $translations[currentLanguage()] = array_replace(
        $translations[currentLanguage()],
        additionalUiTranslations(currentLanguage())
    );

    return $translations[currentLanguage()][$key] ?? $fallback ?? $key;
}

function additionalUiTranslations(string $language): array
{
    $translations = [
        'ms' => [
            'Administrator' => 'Pentadbir', 'Admin' => 'Pentadbir', 'Admins' => 'Pentadbir', 'Staff' => 'Kakitangan',
            'Customer' => 'Pelanggan', 'Customers' => 'Pelanggan', 'Driver' => 'Pemandu', 'Role' => 'Peranan', 'User role' => 'Peranan pengguna',
            'User Details' => 'Butiran Pengguna', 'Manage all users and roles' => 'Urus semua pengguna dan peranan', 'Add New User' => 'Tambah Pengguna Baharu', 'Total Users' => 'Jumlah Pengguna',
            'Name' => 'Nama', 'Email' => 'E-mel', 'Phone' => 'Telefon', 'IC Number' => 'Nombor IC', 'Bank Name' => 'Nama Bank', 'Bank Account No.' => 'No. Akaun Bank',
            'Bank Account Number' => 'Nombor Akaun Bank', 'Address' => 'Alamat', 'Registered' => 'Berdaftar', 'No users found' => 'Tiada pengguna ditemui', 'Edit user' => 'Sunting pengguna',
            'You cannot edit your own role or status' => 'Anda tidak boleh menyunting peranan atau status sendiri', 'Full Name' => 'Nama Penuh', 'Select Bank' => 'Pilih Bank',
            'Reset Password' => 'Tetapkan Semula Kata Laluan', '(leave blank to keep current)' => '(biarkan kosong untuk kekalkan kata laluan semasa)',
            'Search users' => 'Cari pengguna', 'Search users...' => 'Cari pengguna...', 'Searching...' => 'Mencari...', 'Enter account number' => 'Masukkan nombor akaun', 'Enter new password' => 'Masukkan kata laluan baharu',
            'Market Price' => 'Harga Pasaran', 'Prices auto-update as the admin overrides them below.' => 'Harga dikemas kini secara automatik apabila pentadbir mengubahnya di bawah.',
            'Admin Override' => 'Tetapan Harga Pentadbir', 'Price' => 'Harga', 'Unit' => 'Unit', 'Choose category' => 'Pilih kategori', 'Update' => 'Kemas Kini', 'RM per' => 'RM setiap',
            'Price Activity Log' => 'Log Aktiviti Harga', 'Updated' => 'Dikemas kini', 'No price changes logged yet.' => 'Tiada perubahan harga direkodkan lagi.',
            'Price updated successfully.' => 'Harga berjaya dikemas kini.', 'Unable to load pricing data.' => 'Tidak dapat memuatkan data harga.', 'You are not authorized to update prices.' => 'Anda tidak dibenarkan mengemas kini harga.',
            'Please select a category.' => 'Sila pilih kategori.', 'Please enter a valid price.' => 'Sila masukkan harga yang sah.', 'The price could not be updated.' => 'Harga tidak dapat dikemas kini.',
            'Revenue Summary' => 'Ringkasan Hasil', 'Based on all completed bookings · Prices applied at current live rates' => 'Berdasarkan semua tempahan selesai · Harga menggunakan kadar semasa',
            'TODAY' => 'HARI INI', 'THIS MONTH' => 'BULAN INI', 'THIS YEAR' => 'TAHUN INI', 'completed jobs' => 'kerja selesai', 'total jobs' => 'jumlah kerja',
            'Revenue Trend — Last 7 Days' => 'Trend Hasil — 7 Hari Terakhir', 'RM per day' => 'RM sehari', 'No paid sales in the last 7 days.' => 'Tiada jualan berbayar dalam 7 hari terakhir.',
            'Category Share —' => 'Bahagian Kategori —', 'No completed jobs this year.' => 'Tiada kerja selesai tahun ini.', 'job' => 'kerja', 'jobs' => 'kerja', 'kg collected' => 'kg dikutip',
            'No completed jobs this period' => 'Tiada kerja selesai untuk tempoh ini', 'DAILY —' => 'HARIAN —',
            'Add User' => 'Tambah Pengguna', 'Create a new Staff, Driver, or Customer account' => 'Cipta akaun Kakitangan, Pemandu atau Pelanggan baharu',
            'Account Information' => 'Maklumat Akaun', 'Phone Number' => 'Nombor Telefon', 'Identification &amp; Role' => 'Pengenalan &amp; Peranan',
            'Payroll Details' => 'Butiran Gaji', 'For Staff &amp; Driver only — leave blank for Customer accounts.' => 'Untuk Kakitangan &amp; Pemandu sahaja — biarkan kosong bagi akaun Pelanggan.',
            'New accounts are created with the temporary password' => 'Akaun baharu dicipta dengan kata laluan sementara',
            'Waste Calculator' => 'Kalkulator Sisa', 'Add Waste' => 'Tambah Sisa', 'Calculating for:' => 'Mengira untuk:', 'No pending bookings available.' => 'Tiada tempahan menunggu tersedia.',
            'Estimated selection' => 'Pilihan anggaran', 'Clear All' => 'Kosongkan Semua', 'Save & Confirm' => 'Simpan & Sahkan', 'Breakdown' => 'Pecahan', 'Total Value' => 'Jumlah Nilai',
            'Calculation Successful!' => 'Pengiraan Berjaya!', 'Redirecting to Records...' => 'Mengalihkan ke Rekod...',
            'Booking Type' => 'Jenis Tempahan', 'Pickup' => 'Kutipan', 'Walk-in' => 'Walk-in', 'Booking Date' => 'Tarikh Tempahan', 'Order Placed Successful!' => 'Tempahan Berjaya Dibuat!',
            'Queue Management' => 'Pengurusan Giliran', 'Manage pending, active, completed, and cancelled arrival requests.' => 'Urus permintaan ketibaan yang menunggu, aktif, selesai dan dibatalkan.',
            'Back To GPS →' => 'Kembali ke GPS →', 'Ongoing' => 'Sedang Berlangsung', 'Loading queue…' => 'Memuatkan giliran…', 'Location' => 'Lokasi', 'Created' => 'Dicipta',
            'Start' => 'Mula', 'Complete' => 'Selesaikan', 'No queue items found.' => 'Tiada item giliran ditemui.',
            'Add Waste Category' => 'Tambah Kategori Sisa', 'Create new pricing items for the calculator and live pricing list' => 'Cipta item harga baharu untuk kalkulator dan senarai harga semasa',
            'Back to Calculator' => 'Kembali ke Kalkulator', 'Category Name *' => 'Nama Kategori *', 'Unit Price (RM) *' => 'Harga Seunit (RM) *', 'Unit Type' => 'Jenis Unit', 'Save Category' => 'Simpan Kategori',
            'RECEIPT' => 'RESIT', 'Waste Collection Statement' => 'Penyata Kutipan Sisa', 'Item' => 'Item', 'Weight / Rate' => 'Berat / Kadar', 'Amount (RM)' => 'Amaun (RM)', 'Total (RM)' => 'Jumlah (RM)',
            'Collected Items' => 'Item Dikumpul', 'No items recorded' => 'Tiada item direkodkan', 'Pickup Date' => 'Tarikh Kutipan', 'Receipt No.' => 'No. Resit', 'PAYMENT' => 'PEMBAYARAN',
            'THANK YOU FOR RECYCLING WITH RECYCLON' => 'TERIMA KASIH KERANA MENGITAR SEMULA BERSAMA RECYCLON', 'Together for a cleaner environment.' => 'Bersama demi alam sekitar yang lebih bersih.',
            'Track My Pickup' => 'Jejak Kutipan Saya', 'Follow your assigned lorry and estimated arrival time.' => 'Ikuti lori yang ditugaskan dan anggaran masa ketibaan.',
            'Checking...' => 'Memeriksa...', 'Loading lorry location...' => 'Memuatkan lokasi lori...', 'Your pickup destination' => 'Destinasi kutipan anda', 'Assigned location' => 'Lokasi yang ditugaskan',
            'No results found' => 'Tiada hasil ditemui', 'Search failed' => 'Carian gagal',
            'Turn everyday recycling into a better tomorrow.' => 'Jadikan kitar semula harian sebagai masa depan yang lebih baik.',
            'Simple from start to finish' => 'Mudah dari mula hingga selesai', 'How pickup works' => 'Cara kutipan berfungsi', 'Separate your materials' => 'Asingkan bahan anda',
            'Schedule a pickup' => 'Jadualkan kutipan', 'We collect and process' => 'Kami kutip dan proses', 'What you can pass on' => 'Bahan yang boleh anda hantar',
            'Recyclable materials' => 'Bahan boleh dikitar semula', 'Current recycling prices' => 'Harga kitar semula semasa', 'View All Prices' => 'Lihat Semua Harga',
            'Why Recyclon' => 'Mengapa Recyclon', 'Ready to start recycling?' => 'Bersedia untuk mula mengitar semula?', 'Schedule Your First Pickup' => 'Jadualkan Kutipan Pertama Anda',
            'TOTAL BOOKINGS' => 'JUMLAH TEMPAHAN', 'RECYCLED' => 'DIKITAR SEMULA', 'EARNED' => 'DIPEROLEH', 'CURRENT PRICES' => 'HARGA SEMASA',
            'MY BOOKINGS HISTORY' => 'SEJARAH TEMPAHAN SAYA', 'No booking history yet' => 'Belum ada sejarah tempahan', 'Schedule a Pickup' => 'Jadualkan Kutipan',
            'GPS Tracker' => 'Penjejak GPS', 'Real-time fleet positions — Alor Setar, Kedah' => 'Kedudukan armada masa nyata — Alor Setar, Kedah',
            'Queue Management →' => 'Pengurusan Giliran →', 'History →' => 'Sejarah →', 'Driver Assignment' => 'Penugasan Pemandu',
            'Assign an active Driver account to a lorry.' => 'Tugaskan akaun Pemandu aktif kepada lori.', 'Assign Driver' => 'Tugaskan Pemandu',
            'Unassign driver' => 'Nyah-tugaskan pemandu', 'Lorry Management' => 'Pengurusan Lori', 'Manage Lorries' => 'Urus Lori',
            'Create, edit, remove lorries, and update their assigned driver, status, GPS, and destination data.' => 'Cipta, sunting, padam lori serta kemas kini pemandu, status, GPS dan data destinasi.',
            'Plate number' => 'Nombor plat', 'Unassigned' => 'Tidak ditugaskan', 'On Duty' => 'Bertugas', 'Maintenance' => 'Penyelenggaraan',
            'Create Lorry' => 'Cipta Lori', 'Update Lorry' => 'Kemas Kini Lori', 'Clear' => 'Kosongkan', 'Plate' => 'Plat', 'Current position' => 'Kedudukan semasa', 'Destination' => 'Destinasi',
            'Fleet Map — Click to set arrival destination' => 'Peta Armada — Klik untuk tetapkan destinasi ketibaan', 'Refresh' => 'Muat Semula', 'Fit All' => 'Papar Semua', 'LIVE' => 'LANGSUNG', 'Syncing…' => 'Menyegerak…',
            'Set Arrival Destination' => 'Tetapkan Destinasi Ketibaan', 'Select a lorry, then click the map to set its arrival location' => 'Pilih lori, kemudian klik peta untuk tetapkan lokasi ketibaannya',
            'Choose a lorry to see its assigned driver' => 'Pilih lori untuk melihat pemandu yang ditugaskan', 'Assigned Driver' => 'Pemandu Ditugaskan', 'Arrival Location' => 'Lokasi Ketibaan',
            'Use my location' => 'Gunakan lokasi saya', 'Set Arrival' => 'Tetapkan Ketibaan', 'Active queue' => 'Giliran aktif', 'Ongoing arrival destinations currently assigned to the fleet.' => 'Destinasi ketibaan sedang berlangsung yang ditugaskan kepada armada.',
            'No ongoing queued destinations found.' => 'Tiada destinasi giliran sedang berlangsung ditemui.', 'Automation &amp; Integration' => 'Automasi &amp; Integrasi',
            'Connect fleet updates and driver tracking to the operational map.' => 'Sambungkan kemas kini armada dan penjejakan pemandu ke peta operasi.', 'API Endpoint' => 'Titik Akhir API',
            'Driver Auto-Tracker' => 'Penjejak Automatik Pemandu', 'Open Driver Page' => 'Buka Halaman Pemandu',
            'Edit Booking' => 'Sunting Tempahan', 'Schedule a waste collection pickup' => 'Jadualkan kutipan sisa', 'Editing Booking' => 'Menyunting Tempahan',
            'Customer Information' => 'Maklumat Pelanggan', 'Select Existing Customer (Optional)' => 'Pilih Pelanggan Sedia Ada (Pilihan)', '-- Select Existing Customer --' => '-- Pilih Pelanggan Sedia Ada --',
            'Collection Address' => 'Alamat Kutipan', 'Waste to be Collected' => 'Sisa untuk Dikumpul', 'Price:' => 'Harga:',
            'Additional Note' => 'Nota Tambahan', 'Note / Special Instructions' => 'Nota / Arahan Khas',
            'Example: Please collect the waste from the back entrance.' => 'Contoh: Sila kutip sisa dari pintu masuk belakang.',
            'Add any special instructions for the waste collection team.' => 'Tambahkan sebarang arahan khas untuk pasukan kutipan sisa.',
            'Pickup Date *' => 'Tarikh Kutipan *', 'Select the date you want the waste to be collected.' => 'Pilih tarikh sisa hendak dikumpul.',
            'Scheduled' => 'Dijadualkan', 'Assign Lorry / Driver (Optional)' => 'Tugaskan Lori / Pemandu (Pilihan)', '-- Unassigned --' => '-- Tidak Ditugaskan --',
            'Update Booking' => 'Kemas Kini Tempahan', 'Create Booking' => 'Cipta Tempahan', 'Redirecting to Dashboard...' => 'Mengalihkan ke Papan Pemuka...',
            'Unable to load customer information.' => 'Tidak dapat memuatkan maklumat pelanggan.', 'Unable to load dropdown lists from users table.' => 'Tidak dapat memuatkan senarai pilihan pengguna.',
            "Please enter the customer's full name." => 'Sila masukkan nama penuh pelanggan.', 'Please enter a collection address.' => 'Sila masukkan alamat kutipan.',
            'Please select a pickup date.' => 'Sila pilih tarikh kutipan.', 'Please select at least one waste type.' => 'Sila pilih sekurang-kurangnya satu jenis sisa.',
            "TODAY'S BOOKINGS" => 'TEMPAHAN HARI INI', 'confirmed' => 'disahkan', 'COMPLETED REVENUE' => 'HASIL SELESAI',
            'From completed jobs' => 'Daripada kerja selesai', 'ACTIVE TRUCKS' => 'LORI AKTIF', 'total fleet' => 'jumlah armada',
            'Awaiting dispatch' => 'Menunggu penghantaran', 'MARKET PRICES' => 'HARGA PASARAN', 'Edit Price' => 'Sunting Harga',
            'No pricing data available.' => 'Tiada data harga tersedia.', 'RECENT BOOKINGS' => 'TEMPAHAN TERKINI',
            'No recent bookings found.' => 'Tiada tempahan terkini ditemui.',
        ],
        'zh' => [
            'Administrator' => '管理员', 'Admin' => '管理员', 'Admins' => '管理员', 'Staff' => '员工', 'Customer' => '客户', 'Customers' => '客户', 'Driver' => '司机', 'Role' => '角色', 'User role' => '用户角色',
            'User Details' => '用户详情', 'Manage all users and roles' => '管理所有用户和角色', 'Add New User' => '添加新用户', 'Total Users' => '用户总数',
            'Name' => '姓名', 'Email' => '电子邮件', 'Phone' => '电话', 'IC Number' => '身份证号码', 'Bank Name' => '银行名称', 'Bank Account No.' => '银行账号',
            'Bank Account Number' => '银行账号', 'Address' => '地址', 'Registered' => '注册日期', 'No users found' => '未找到用户', 'Edit user' => '编辑用户',
            'You cannot edit your own role or status' => '您无法编辑自己的角色或状态', 'Full Name' => '全名', 'Select Bank' => '选择银行', 'Reset Password' => '重设密码',
            '(leave blank to keep current)' => '（留空以保留当前密码）', 'Search users' => '搜索用户', 'Search users...' => '搜索用户...', 'Searching...' => '正在搜索...',
            'Enter account number' => '输入账号', 'Enter new password' => '输入新密码', 'Market Price' => '市场价格', 'Prices auto-update as the admin overrides them below.' => '管理员在下方修改价格后，价格会自动更新。',
            'Admin Override' => '管理员价格调整', 'Price' => '价格', 'Unit' => '单位', 'Choose category' => '选择类别', 'Update' => '更新', 'RM per' => 'RM 每',
            'Price Activity Log' => '价格活动记录', 'Updated' => '已更新', 'No price changes logged yet.' => '尚未记录价格变更。', 'Price updated successfully.' => '价格更新成功。',
            'Unable to load pricing data.' => '无法加载价格数据。', 'You are not authorized to update prices.' => '您无权更新价格。', 'Please select a category.' => '请选择类别。',
            'Please enter a valid price.' => '请输入有效价格。', 'The price could not be updated.' => '无法更新价格。', 'Revenue Summary' => '收入摘要',
            'Based on all completed bookings · Prices applied at current live rates' => '基于所有已完成的预约 · 按当前实时价格计算', 'TODAY' => '今天', 'THIS MONTH' => '本月', 'THIS YEAR' => '今年',
            'completed jobs' => '已完成工作', 'total jobs' => '工作总数', 'Revenue Trend — Last 7 Days' => '收入趋势 — 最近 7 天', 'RM per day' => 'RM 每天',
            'No paid sales in the last 7 days.' => '最近 7 天没有已付款销售。', 'Category Share —' => '类别占比 —', 'No completed jobs this year.' => '今年没有已完成工作。',
            'job' => '项工作', 'jobs' => '项工作', 'kg collected' => '公斤已收集', 'No completed jobs this period' => '此期间没有已完成工作', 'DAILY —' => '每日 —',
            'Add User' => '添加用户', 'Create a new Staff, Driver, or Customer account' => '创建新的员工、司机或客户账号',
            'Account Information' => '账号信息', 'Phone Number' => '电话号码', 'Identification &amp; Role' => '身份信息与角色', 'Payroll Details' => '工资资料',
            'For Staff &amp; Driver only — leave blank for Customer accounts.' => '仅适用于员工和司机 — 客户账号请留空。', 'New accounts are created with the temporary password' => '新账号使用临时密码创建',
            'Waste Calculator' => '废物计算器', 'Add Waste' => '添加废物', 'Calculating for:' => '正在为此对象计算：', 'No pending bookings available.' => '没有待处理预约。',
            'Estimated selection' => '预计选择', 'Clear All' => '清除全部', 'Save & Confirm' => '保存并确认', 'Breakdown' => '明细', 'Total Value' => '总价值',
            'Calculation Successful!' => '计算成功！', 'Redirecting to Records...' => '正在跳转到记录...',
            'Booking Type' => '预约类型', 'Pickup' => '上门收集', 'Walk-in' => '到店', 'Booking Date' => '预约日期', 'Order Placed Successful!' => '预约提交成功！',
            'Queue Management' => '队列管理', 'Manage pending, active, completed, and cancelled arrival requests.' => '管理待处理、进行中、已完成和已取消的到达请求。',
            'Back To GPS →' => '返回 GPS →', 'Ongoing' => '进行中', 'Loading queue…' => '正在加载队列…', 'Location' => '位置', 'Created' => '创建时间',
            'Start' => '开始', 'Complete' => '完成', 'No queue items found.' => '未找到队列项目。',
            'Add Waste Category' => '添加废物类别', 'Create new pricing items for the calculator and live pricing list' => '为计算器和实时价格列表创建新的价格项目',
            'Back to Calculator' => '返回计算器', 'Category Name *' => '类别名称 *', 'Unit Price (RM) *' => '单价 (RM) *', 'Unit Type' => '单位类型', 'Save Category' => '保存类别',
            'RECEIPT' => '收据', 'Waste Collection Statement' => '废物收集结单', 'Item' => '项目', 'Weight / Rate' => '重量 / 费率', 'Amount (RM)' => '金额 (RM)', 'Total (RM)' => '总计 (RM)',
            'Collected Items' => '已收集项目', 'No items recorded' => '没有记录项目', 'Pickup Date' => '收集日期', 'Receipt No.' => '收据编号', 'PAYMENT' => '付款',
            'THANK YOU FOR RECYCLING WITH RECYCLON' => '感谢您与 RECYCLON 一起回收', 'Together for a cleaner environment.' => '一起打造更洁净的环境。',
            'Track My Pickup' => '追踪我的收集', 'Follow your assigned lorry and estimated arrival time.' => '查看已分配的卡车和预计到达时间。',
            'Checking...' => '正在检查...', 'Loading lorry location...' => '正在加载卡车位置...', 'Your pickup destination' => '您的收集目的地', 'Assigned location' => '已分配位置',
            'No results found' => '未找到结果', 'Search failed' => '搜索失败',
            'Turn everyday recycling into a better tomorrow.' => '让日常回收成就更美好的明天。',
            'Simple from start to finish' => '从开始到结束都很简单', 'How pickup works' => '收集流程', 'Separate your materials' => '分类您的材料',
            'Schedule a pickup' => '预约收集', 'We collect and process' => '我们收集并处理', 'What you can pass on' => '您可以交出的材料',
            'Recyclable materials' => '可回收材料', 'Current recycling prices' => '当前回收价格', 'View All Prices' => '查看所有价格',
            'Why Recyclon' => '为什么选择 Recyclon', 'Ready to start recycling?' => '准备开始回收了吗？', 'Schedule Your First Pickup' => '预约您的首次收集',
            'TOTAL BOOKINGS' => '预约总数', 'RECYCLED' => '已回收', 'EARNED' => '已获得', 'CURRENT PRICES' => '当前价格',
            'MY BOOKINGS HISTORY' => '我的预约记录', 'No booking history yet' => '尚无预约记录', 'Schedule a Pickup' => '预约收集',
            'GPS Tracker' => 'GPS 追踪器', 'Real-time fleet positions — Alor Setar, Kedah' => '实时车队位置 — 吉打亚罗士打',
            'Queue Management →' => '队列管理 →', 'History →' => '历史记录 →', 'Driver Assignment' => '司机分配',
            'Assign an active Driver account to a lorry.' => '将活跃司机账号分配给卡车。', 'Assign Driver' => '分配司机',
            'Unassign driver' => '取消分配司机', 'Lorry Management' => '卡车管理', 'Manage Lorries' => '管理卡车',
            'Create, edit, remove lorries, and update their assigned driver, status, GPS, and destination data.' => '创建、编辑、移除卡车，并更新其司机、状态、GPS 和目的地资料。',
            'Plate number' => '车牌号码', 'Unassigned' => '未分配', 'On Duty' => '执勤中', 'Maintenance' => '维护中',
            'Create Lorry' => '创建卡车', 'Update Lorry' => '更新卡车', 'Clear' => '清除', 'Plate' => '车牌', 'Current position' => '当前位置', 'Destination' => '目的地',
            'Fleet Map — Click to set arrival destination' => '车队地图 — 点击设置到达目的地', 'Refresh' => '刷新', 'Fit All' => '显示全部', 'LIVE' => '实时', 'Syncing…' => '同步中…',
            'Set Arrival Destination' => '设置到达目的地', 'Select a lorry, then click the map to set its arrival location' => '选择卡车，然后点击地图设置其到达位置',
            'Choose a lorry to see its assigned driver' => '选择卡车以查看其分配的司机', 'Assigned Driver' => '已分配司机', 'Arrival Location' => '到达位置',
            'Use my location' => '使用我的位置', 'Set Arrival' => '设置到达', 'Active queue' => '活动队列', 'Ongoing arrival destinations currently assigned to the fleet.' => '当前分配给车队的进行中到达目的地。',
            'No ongoing queued destinations found.' => '未找到进行中的队列目的地。', 'Automation &amp; Integration' => '自动化与集成',
            'Connect fleet updates and driver tracking to the operational map.' => '将车队更新和司机追踪连接到运营地图。', 'API Endpoint' => 'API 端点',
            'Driver Auto-Tracker' => '司机自动追踪器', 'Open Driver Page' => '打开司机页面',
            'Edit Booking' => '编辑预约', 'Schedule a waste collection pickup' => '安排废物收集', 'Editing Booking' => '正在编辑预约',
            'Customer Information' => '客户信息', 'Select Existing Customer (Optional)' => '选择现有客户（可选）', '-- Select Existing Customer --' => '-- 选择现有客户 --',
            'Collection Address' => '收集地址', 'Waste to be Collected' => '待收集废物', 'Price:' => '价格：',
            'Additional Note' => '附加备注', 'Note / Special Instructions' => '备注 / 特别说明',
            'Example: Please collect the waste from the back entrance.' => '示例：请从后门收集废物。',
            'Add any special instructions for the waste collection team.' => '为废物收集团队添加特别说明。',
            'Pickup Date *' => '收集日期 *', 'Select the date you want the waste to be collected.' => '选择您希望收集废物的日期。',
            'Scheduled' => '已安排', 'Assign Lorry / Driver (Optional)' => '分配卡车 / 司机（可选）', '-- Unassigned --' => '-- 未分配 --',
            'Update Booking' => '更新预约', 'Create Booking' => '创建预约', 'Redirecting to Dashboard...' => '正在跳转到仪表板...',
            'Unable to load customer information.' => '无法加载客户信息。', 'Unable to load dropdown lists from users table.' => '无法从用户表加载下拉列表。',
            "Please enter the customer's full name." => '请输入客户的全名。', 'Please enter a collection address.' => '请输入收集地址。',
            'Please select a pickup date.' => '请选择收集日期。', 'Please select at least one waste type.' => '请至少选择一种废物类型。',
            "TODAY'S BOOKINGS" => '今日预约', 'confirmed' => '已确认', 'COMPLETED REVENUE' => '已完成收入',
            'From completed jobs' => '来自已完成工作', 'ACTIVE TRUCKS' => '活跃卡车', 'total fleet' => '车队总数',
            'Awaiting dispatch' => '等待派送', 'MARKET PRICES' => '市场价格', 'Edit Price' => '编辑价格',
            'No pricing data available.' => '没有可用价格数据。', 'RECENT BOOKINGS' => '最近预约',
            'No recent bookings found.' => '未找到最近预约。',
        ],
    ];

    return $translations[$language] ?? [];
}

/**
 * Covers recurring labels already present across the existing PHP pages.
 * This keeps the legacy pages multilingual while they are progressively
 * migrated to explicit t('key') calls.
 */
function translatePageOutput(string $output): string
{
    $language = currentLanguage();
    if ($language === 'en') {
        return $output;
    }

    $replacements = [
        'ms' => [
            'Booking Records' => 'Rekod Tempahan', 'Live Pricing' => 'Harga Semasa',
            'Track My Pickup' => 'Jejak Kutipan Saya', 'Waste Calculator' => 'Kalkulator Sisa',
            'Type of Waste' => 'Jenis Sisa', 'QUANTITIES (KG)' => 'KUANTITI (KG)',
            'Add User' => 'Tambah Pengguna', 'Driver Dashboard' => 'Papan Pemuka Pemandu',
            'Staff Dashboard' => 'Papan Pemuka Staf', 'Completed History' => 'Sejarah Selesai',
            'GPS Tracker' => 'Penjejak GPS', 'Revenue Summary' => 'Ringkasan Hasil',
            'Queue Management' => 'Pengurusan Giliran', 'Add Waste Category' => 'Tambah Kategori Sisa',
            'New Booking' => 'Tempahan Baharu', 'My Profile' => 'Profil Saya',
            'User Details' => 'Butiran Pengguna', 'Dashboard' => 'Papan Pemuka',
            'Customer' => 'Pelanggan', 'Driver' => 'Pemandu', 'Booking' => 'Tempahan',
            'Bookings' => 'Tempahan', 'Category' => 'Kategori', 'Categories' => 'Kategori',
            'Weight' => 'Berat', 'Date' => 'Tarikh', 'Time' => 'Masa', 'Status' => 'Status',
            'Action' => 'Tindakan', 'Actions' => 'Tindakan', 'Search' => 'Cari',
            'Filter' => 'Tapis', 'Save Changes' => 'Simpan Perubahan', 'Save' => 'Simpan',
            'Cancel' => 'Batal', 'Delete' => 'Padam', 'Edit' => 'Sunting', 'View' => 'Lihat',
            'Back' => 'Kembali', 'Close' => 'Tutup', 'Submit' => 'Hantar', 'Calculate' => 'Kira',
            'Receipt' => 'Resit', 'History' => 'Sejarah', 'Pending' => 'Menunggu',
            'Confirmed' => 'Disahkan', 'Completed' => 'Selesai', 'Cancelled' => 'Dibatalkan',
            'Available' => 'Tersedia', 'Assigned' => 'Ditugaskan', 'Active' => 'Aktif',
            'Inactive' => 'Tidak Aktif', 'Profile' => 'Profil', 'Logout' => 'Log Keluar',
        ],
        'zh' => [
            'Booking Records' => '预约记录', 'Live Pricing' => '实时价格',
            'Track My Pickup' => '追踪我的收集', 'Waste Calculator' => '废物计算器',
            'Type of Waste' => '废物类型', 'QUANTITIES (KG)' => '数量（公斤）',
            'Add User' => '添加用户', 'Driver Dashboard' => '司机仪表板',
            'Staff Dashboard' => '员工仪表板', 'Completed History' => '已完成记录',
            'GPS Tracker' => 'GPS 追踪器', 'Revenue Summary' => '收入摘要',
            'Queue Management' => '队列管理', 'Add Waste Category' => '添加废物类别',
            'New Booking' => '新预约', 'My Profile' => '我的个人资料',
            'User Details' => '用户详情', 'Dashboard' => '仪表板',
            'Customer' => '客户', 'Driver' => '司机', 'Booking' => '预约',
            'Bookings' => '预约', 'Category' => '类别', 'Categories' => '类别',
            'Weight' => '重量', 'Date' => '日期', 'Time' => '时间', 'Status' => '状态',
            'Action' => '操作', 'Actions' => '操作', 'Search' => '搜索',
            'Filter' => '筛选', 'Save Changes' => '保存更改', 'Save' => '保存',
            'Cancel' => '取消', 'Delete' => '删除', 'Edit' => '编辑', 'View' => '查看',
            'Back' => '返回', 'Close' => '关闭', 'Submit' => '提交', 'Calculate' => '计算',
            'Receipt' => '收据', 'History' => '历史记录', 'Pending' => '待处理',
            'Confirmed' => '已确认', 'Completed' => '已完成', 'Cancelled' => '已取消',
            'Available' => '可用', 'Assigned' => '已分配', 'Active' => '活跃',
            'Inactive' => '未激活', 'Profile' => '个人资料', 'Logout' => '登出',
        ],
    ];

    $replaceText = static fn(string $text): string => strtr($text, array_replace(
        $replacements[$language] ?? [],
        additionalUiTranslations($language)
    ));

    $replaceVisibleText = static function (string $html) use ($replaceText): string {
        $html = preg_replace_callback(
            '/>([^<>]+)</u',
            static fn(array $match): string => '>' . $replaceText($match[1]) . '<',
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/\b(placeholder|title|aria-label)="([^"]*)"/u',
            static fn(array $match): string => $match[1] . '="' . $replaceText($match[2]) . '"',
            $html
        ) ?? $html;
    };

    // Keep scripts and styles untouched. In particular, IDs such as
    // topbar-time-desktop must remain stable for JavaScript to work.
    $parts = preg_split(
        '/(<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>)/is',
        $output,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ($parts === false) {
        return $output;
    }

    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $parts[$index] = $replaceVisibleText($part);
        }
    }

    return implode('', $parts);
}

function startTranslationBuffer(): void
{
    if (currentLanguage() !== 'en' && !defined('RECYCLON_TRANSLATION_BUFFER')) {
        define('RECYCLON_TRANSLATION_BUFFER', true);
        ob_start('translatePageOutput');
    }
}
