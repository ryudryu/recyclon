<?php
require_once __DIR__ . '/config/session.php';


session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff', 'Customer'], true)) {
    header('Location: auth/login.php');
    exit;
}
include "header.php";

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sale_id <= 0) {
    die("Invalid sale ID.");
}

/*
|--------------------------------------------------------------------------
| Get sale + booking information
|--------------------------------------------------------------------------
| Keyed by sale_id (the actual transaction/receipt), not booking_id,
| since one booking can have multiple sales over time.
*/
$sql = "SELECT 
            s.sale_id,
            s.booking_id,
            s.total_amount,
            s.sale_date,
            s.payment_status,
            s.customer_id,
            u.name AS customer_name,
            u.phone AS phone,
            b.booking_date,
            b.pickup_date,
            b.status AS booking_status
        FROM sales s
        LEFT JOIN booking b 
            ON s.booking_id = b.booking_id
        LEFT JOIN users u
            ON s.customer_id = u.user_id
        WHERE s.sale_id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$sale_id]);

$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    die("Receipt not found.");
}

if ($_SESSION['role'] === 'Customer' && (int)$receipt['customer_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    die("You do not have access to this receipt.");
}

if (strtolower(trim((string)($receipt['booking_status'] ?? ''))) !== 'completed'
    || strtoupper(trim((string)($receipt['payment_status'] ?? ''))) !== 'PAID'
    || $receipt['total_amount'] === null) {
    die("Receipt is available only after staff completes the calculation.");
}

/*
|--------------------------------------------------------------------------
| Get sale items
|--------------------------------------------------------------------------
| sale_items:
| - waste_id
| - weight_kg
| - price_per_kg
| - subtotal
|
| waste_categories:
| - category_name
*/
$itemSql = "SELECT
                si.item_id,
                wc.category_name,
                wc.unit,
                si.weight_kg,
                si.price_per_kg,
                si.subtotal
            FROM sale_items si
            LEFT JOIN waste_categories wc
                ON si.waste_id = wc.waste_id
            WHERE si.sale_id = ?
            ORDER BY si.item_id ASC";

$itemStmt = $conn->prepare($itemSql);
$itemStmt->execute([$receipt['sale_id']]);

$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// A sales row may exist for an estimate created with a booking. The receipt
// is only valid once staff has saved at least one weighed item as part of the
// completed transaction.
$items = array_values(array_filter($items, static function ($item) {
    return $item['weight_kg'] !== null && (float)$item['weight_kg'] > 0;
}));

if (!$items) {
    die("Receipt is available only after staff completes the calculation.");
}

/*
|--------------------------------------------------------------------------
| Calculate total weight
|--------------------------------------------------------------------------
*/
$total_weight = 0;

foreach ($items as $item) {
    $total_weight += (float)$item['weight_kg'];
}

/*
|--------------------------------------------------------------------------
| Receipt information
|--------------------------------------------------------------------------
| Receipt number is based on sale_id, since that's the unique transaction
| this receipt represents (a booking can have several receipts).
*/
$receipt_no = "RC-" . str_pad(
    $receipt['sale_id'],
    5,
    '0',
    STR_PAD_LEFT
);

$transaction_date = $receipt['sale_date']
    ? date("d/m/Y", strtotime($receipt['sale_date']))
    : date("d/m/Y");

$transaction_time = $receipt['sale_date']
    ? date("h:i A", strtotime($receipt['sale_date']))
    : date("h:i A");

$payment_status = strtoupper($receipt['payment_status'] ?? 'PENDING');
?>

<div class="page-body">

        <?php
 include 'sidebar.php'; ?>

        <div class="content-panel receipt-page">

            <!-- =========================
                 STATEMENT (Maxis-style)
            ========================== -->
            <div class="statement" id="receipt">

                <!-- Brand Header -->
                <div class="statement-header">

                    <div class="statement-logo">
                        <img src="assets/img/logo-recyclon-dark-transparent.png" alt="RECYCLON Logo">
                    </div>

                    <div class="statement-title">
                        <h1>RECEIPT</h1>
                        <p>Waste Collection Statement</p>
                    </div>

                </div>

                <div class="statement-date">
                    Statement Date: <strong><?php
 echo htmlspecialchars($transaction_date); ?></strong>
                </div>

                <!-- Identifier Pill -->
                <div class="statement-pill">
                    <?php
 echo htmlspecialchars($receipt_no); ?>&nbsp;&nbsp;&nbsp;Booking #<?php
 echo htmlspecialchars($receipt['booking_id']); ?>
                </div>

                <!-- Items Table -->
                <table class="statement-table">

                    <thead>
                        <tr>
                            <th class="col-item">Item</th>
                            <th class="col-detail">Weight / Rate</th>
                            <th class="col-amount">Amount (RM)</th>
                            <th class="col-total">Total (RM)</th>
                        </tr>
                    </thead>

                    <tbody>

                        <tr class="section-row">
                            <td colspan="4">
                                <span class="section-pill">Collected Items</span>
                            </td>
                        </tr>

                        <?php
 if (count($items) > 0): ?>

                            <?php
 foreach ($items as $item): ?>

                                <tr>
                                    <td class="col-item">
                                        <?php

                                        echo htmlspecialchars(
                                            $item['category_name'] ?? 'Unknown'
                                        );
                                        ?>
                                    </td>
                                    <td class="col-detail">
                                        <?php

                                        echo number_format((float)$item['weight_kg'], 2);
                                        ?> kg @ RM<?php

                                        echo number_format((float)$item['price_per_kg'], 2);
                                        ?>/kg
                                    </td>
                                    <td class="col-amount">
                                        RM <?php

                                        echo number_format((float)$item['subtotal'], 2);
                                        ?>
                                    </td>
                                    <td class="col-total"></td>
                                </tr>

                            <?php
 endforeach; ?>

                        <?php
 else: ?>

                            <tr>
                                <td colspan="4" class="no-items">No items recorded</td>
                            </tr>

                        <?php
 endif; ?>

                        <tr class="subtotal-row">
                            <td colspan="1">
                                Total Weight
                            </td>
                            <td class="col-total">
                                <?php
 echo number_format($total_weight, 2); ?> kg
                            </td>
                        </tr>

                        <tr class="total-row">
                            <td colspan="3">
                                TOTAL (<?php
 echo count($items); ?> items, excl. any adjustments)
                            </td>
                            <td class="col-total">
                                RM <?php

                                echo number_format(
                                    (float)$receipt['total_amount'],
                                    2
                                );
                                ?>
                            </td>
                        </tr>

                    </tbody>

                </table>

                <!-- Customer / Payment two-column block -->
                <div class="statement-columns">

                    <div class="info-block">
                        <div class="section-title">CUSTOMER</div>

                        <div class="info-row">
                            <span>Name</span>
                            <strong>
                                <?php

                                echo htmlspecialchars(
                                    $receipt['customer_name'] ?: 'Walk-in Customer'
                                );
                                ?>
                            </strong>
                        </div>

                        <div class="info-row">
                            <span>Phone</span>
                            <span>
                                <?php

                                echo htmlspecialchars(
                                    $receipt['phone'] ?: '-'
                                );
                                ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span>Pickup Date</span>
                            <span>
                                <?php
                                $pickupDate = $receipt['pickup_date'] ?: $receipt['booking_date'];
                                echo $pickupDate ? htmlspecialchars(date('d/m/Y', strtotime($pickupDate))) : '-';
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-block">
                        <div class="section-title">PAYMENT</div>

                        <div class="info-row">
                            <span>Status</span>
                            <strong class="<?php
                                            echo strtolower($payment_status);
                                            ?>">
                                <?php
 echo htmlspecialchars($payment_status); ?>
                            </strong>
                        </div>

                        <div class="info-row">
                            <span>Time</span>
                            <span><?php
 echo htmlspecialchars($transaction_time); ?></span>
                        </div>

                        <div class="info-row">
                            <span>Receipt No.</span>
                            <span><?php
 echo htmlspecialchars($receipt_no); ?></span>
                        </div>
                    </div>

                </div>

                <!-- Footer -->
                <div class="statement-footer">
                    <strong>THANK YOU FOR RECYCLING WITH RECYCLON</strong>
                    <p>Together for a cleaner environment.</p>
                </div>

            </div>

            <!-- Buttons -->
            <div class="receipt-buttons">

                <button
                    type="button"
                    class="back-btn"
                    onclick="history.back()">
                    ← Back
                </button>

                <button
                    type="button"
                    class="print-btn"
                    onclick="window.print()">
                    🖨 Print / Save PDF
                </button>

            </div>

        </div>

</div>

<?php
 include 'footer.php'; ?>


<style>
    /* =========================================================
   PAGE
========================================================= */

    .receipt-page {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        padding: 35px 20px;
        background: #f1f5f9;
    }


    /* =========================================================
   STATEMENT (A4-style, Maxis-inspired)
========================================================= */

    .statement {

        width: 720px;
        max-width: 100%;

        background: #ffffff;

        padding: 40px 45px 35px;

        color: #1f2937;

        font-family: Arial, Helvetica, sans-serif;

        font-size: 13px;

        box-shadow:
            0 10px 30px rgba(15, 23, 42, 0.12);
    }


    /* =========================================================
   HEADER
========================================================= */

    .statement-header {

        display: flex;

        align-items: center;

        justify-content: space-between;

        margin-bottom: 18px;
    }

    .statement-logo img {

        height: 42px;
    }

    .statement-title {

        text-align: right;
    }

    .statement-title h1 {

        margin: 0;

        font-size: 22px;

        font-weight: 900;

        letter-spacing: 3px;

        color: #198754;
    }

    .statement-title p {

        margin: 2px 0 0;

        font-size: 12px;

        font-style: italic;

        color: #555;
    }

    .statement-date {

        font-size: 12px;

        color: #333;

        margin-bottom: 16px;
    }


    /* =========================================================
   IDENTIFIER PILL
========================================================= */

    .statement-pill {

        background: #198754;

        color: #ffffff;

        font-weight: 700;

        font-size: 13px;

        padding: 10px 18px;

        border-radius: 30px;

        margin-bottom: 20px;

        display: inline-block;
    }


    /* =========================================================
   TABLE
========================================================= */

    .statement-table {

        width: 100%;

        border-collapse: collapse;

        margin-bottom: 24px;
    }

    .statement-table thead th {

        text-align: left;

        font-size: 11px;

        color: #555;

        font-weight: 700;

        padding-bottom: 8px;

        border-bottom: 2px solid #198754;
    }

    .statement-table .col-detail,
    .statement-table .col-amount,
    .statement-table .col-total {

        text-align: right;
    }

    .statement-table tbody tr:not(.section-row):not(.subtotal-row):not(.total-row) td {

        padding: 10px 0;

        border-bottom: 1px dashed #d8dde3;

        font-size: 13px;
    }

    .statement-table .col-item {

        font-weight: 700;

        text-transform: uppercase;
    }

    .statement-table .col-detail {

        color: #666;

        font-size: 12px;
    }

    .section-row td {

        padding-top: 16px;

        padding-bottom: 6px;
    }

    .section-pill {

        display: inline-block;

        background: #198754;

        color: #ffffff;

        font-size: 11px;

        font-weight: 700;

        letter-spacing: 0.5px;

        text-transform: uppercase;

        padding: 4px 12px;

        border-radius: 4px;
    }

    .no-items {

        text-align: center;

        padding: 20px 0;

        color: #777;
    }

    .subtotal-row td {

        padding: 10px 0 4px;

        font-size: 12px;

        color: #555;

        border-top: 1px solid #e5e7eb;
    }

    .total-row td {

        padding-top: 10px;

        font-size: 16px;

        font-weight: 900;

        border-top: 2px solid #198754;
    }

    .total-row .col-total {

        color: #198754;

        font-size: 19px;
    }


    /* =========================================================
   CUSTOMER / PAYMENT COLUMNS
========================================================= */

    .statement-columns {

        display: grid;

        grid-template-columns: 1fr 1fr;

        gap: 30px;

        margin-bottom: 24px;
    }

    .info-block .section-title {

        font-size: 12px;

        font-weight: 700;

        letter-spacing: 1px;

        color: #198754;

        margin-bottom: 8px;

        border-bottom: 1px solid #e5e7eb;

        padding-bottom: 4px;
    }

    .info-row {

        display: flex;

        justify-content: space-between;

        gap: 15px;

        margin: 5px 0;

        font-size: 12.5px;
    }

    .info-row span:first-child {

        color: #666;
    }

    .info-row strong {

        font-weight: 700;
    }

    .info-row strong.paid {

        color: #198754;
    }

    .info-row strong.pending {

        color: #f59e0b;
    }

    .info-row strong.cancelled {

        color: #dc3545;
    }

    /* =========================================================
   FOOTER
========================================================= */

    .statement-footer {

        text-align: center;

        padding-top: 16px;

        border-top: 1px solid #e5e7eb;

        font-size: 11px;
    }

    .statement-footer strong {

        font-size: 13px;

        letter-spacing: 0.5px;

        color: #198754;
    }

    .statement-footer p {

        margin: 4px 0 0;

        color: #666;
    }


    /* =========================================================
   BUTTONS
========================================================= */

    .receipt-buttons {

        width: 720px;

        max-width: 100%;

        margin-top: 20px;

        display: flex;

        gap: 10px;
    }

    .receipt-buttons button {

        flex: 1;

        padding: 12px;

        border: none;

        border-radius: 7px;

        cursor: pointer;

        font-size: 14px;

        font-weight: 700;
    }

    .back-btn {

        background: #6c757d;

        color: white;
    }

    .print-btn {

        background: #198754;

        color: white;
    }

    .back-btn:hover {

        background: #5c636a;
    }

    .print-btn:hover {

        background: #157347;
    }


    /* =========================================================
   PRINT (A4)
========================================================= */

    @media print {
    @page {
        size: A4;
        margin: 15mm;
    }

    body * {
        visibility: hidden !important;
    }

    #receipt,
    #receipt * {
        visibility: visible !important;
    }

    body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }

    .page-layout,
    .page-body,
    .content-panel,
    .receipt-page {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        box-shadow: none !important;
    }

    #receipt {
        position: absolute;
        top: 0;
        left: 0;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box;
        background: white !important;
        box-shadow: none !important;
        border: none !important;
    }

    .receipt-buttons {
        display: none !important;
    }
}
</style>
