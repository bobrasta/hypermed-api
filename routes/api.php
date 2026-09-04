<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BatchLotController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UnifiedDashboardController;
use App\Http\Controllers\Api\EmailAccountController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinanceReportController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequisitionController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RevenueController;
use App\Http\Controllers\Api\SalesLeadController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SerialNumberController;
use App\Http\Controllers\Api\ServiceTicketController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StockLevelController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\LateArrivalController;
use App\Http\Controllers\Api\StockOutRequestController;
use App\Http\Controllers\Api\PerDiemController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\PublicHolidayController;
use App\Http\Controllers\Api\HrSettingController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DisciplinaryCaseController;
use App\Http\Controllers\Api\PositionChangeController;
use App\Http\Controllers\Api\HrReportController;
use App\Http\Controllers\Api\VacancyController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\SalaryAdjustmentController;
use App\Http\Controllers\Api\DelegationController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ApplicantController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\InterviewController;
use App\Http\Controllers\Api\PartCannibalizationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketAttachmentController;
use App\Http\Controllers\Api\VendorBillController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::post('auth/login', [AuthController::class, 'login']);

    // License (no auth — must work pre-login for trial gate)
    Route::get('license/check',    [LicenseController::class, 'check']);
    Route::post('license/request', [LicenseController::class, 'store']);

    // Public document sharing — time-limited signed links, no login required
    // (so a hospital contact can open a shared quotation/invoice PDF directly).
    Route::get('public/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])
        ->name('quotations.pdf-public')->middleware('signed');
    Route::get('public/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->name('invoices.pdf-public')->middleware('signed');
    Route::get('public/hr-reports/pdf', [HrReportController::class, 'exportPdf'])
        ->name('hr-reports.pdf-public')->middleware('signed');

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Global search — cross-entity, permission-filtered
        Route::get('search', [SearchController::class, 'index']);

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/inventory', [DashboardController::class, 'inventory']);
        Route::get('dashboard/sales', [DashboardController::class, 'sales']);
        Route::get('dashboard/unified', [UnifiedDashboardController::class, 'index']);

        // Accounting — Chart of Accounts + ledger journal
        Route::get('accounting/accounts', [AccountingController::class, 'accounts']);
        Route::post('accounting/accounts', [AccountingController::class, 'storeAccount']);
        Route::put('accounting/accounts/{account}', [AccountingController::class, 'updateAccount']);
        Route::delete('accounting/accounts/{account}', [AccountingController::class, 'destroyAccount']);
        Route::get('accounting/categories', [AccountingController::class, 'categories']);
        Route::get('accounting/journal',  [AccountingController::class, 'journal']);
        Route::get('accounting/summary',  [AccountingController::class, 'summary']);
        Route::post('accounting/close-period', [AccountingController::class, 'closePeriod']);

        // Expenses
        Route::get('expense-categories', [ExpenseController::class, 'categories']);
        Route::post('expense-categories', [ExpenseController::class, 'storeCategory']);
        Route::put('expense-categories/{expenseCategory}', [ExpenseController::class, 'updateCategory']);
        Route::delete('expense-categories/{expenseCategory}', [ExpenseController::class, 'destroyCategory']);
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
        Route::post('expenses/{expense}/escalate', [ExpenseController::class, 'escalate']);
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
        Route::apiResource('expenses', ExpenseController::class);
        Route::get('settings', [SettingController::class, 'index']);
        Route::put('settings/{key}', [SettingController::class, 'update']);

        // Dynamic access control (Phase 1) — see plan doc "Dynamic access
        // control — Phase 1: permission layer"
        Route::get('me/permissions',        [PermissionController::class, 'me']);
        Route::get('permission-overrides',  [PermissionController::class, 'allOverrides']);
        Route::get('permissions',           [PermissionController::class, 'permissions']);
        Route::get('roles',                 [PermissionController::class, 'roles']);
        Route::post('roles',                [PermissionController::class, 'storeRole']);
        Route::put('roles/{role}',          [PermissionController::class, 'updateRole']);
        Route::delete('roles/{role}',       [PermissionController::class, 'destroyRole']);
        Route::put('roles/{role}/permissions', [PermissionController::class, 'syncRolePermissions']);
        Route::get('users/{user}/permissions',            [PermissionController::class, 'userPermissions']);
        Route::get('users/{user}/permission-overrides',   [PermissionController::class, 'userOverrides']);
        Route::post('users/{user}/permission-overrides',  [PermissionController::class, 'storeUserOverride']);
        Route::delete('users/{user}/permission-overrides/{override}', [PermissionController::class, 'destroyUserOverride']);

        // Vendor Bills (Accounts Payable)
        Route::post('vendor-bills/{vendorBill}/approve',  [VendorBillController::class, 'approve']);
        Route::post('vendor-bills/{vendorBill}/payments', [VendorBillController::class, 'recordPayment']);
        Route::post('vendor-bills/{vendorBill}/cancel',   [VendorBillController::class, 'cancel']);
        Route::apiResource('vendor-bills', VendorBillController::class);

        // Delegated approval authority (Director → temporary delegate)
        Route::get('delegations',                    [DelegationController::class, 'index']);
        Route::post('delegations',                   [DelegationController::class, 'store']);
        Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);

        // Finance reports
        Route::get('finance-reports/vat',           [FinanceReportController::class, 'vat']);
        Route::get('finance-reports/profit-loss',   [FinanceReportController::class, 'profitLoss']);
        Route::get('finance-reports/trial-balance', [FinanceReportController::class, 'trialBalance']);
        Route::get('finance-reports/balance-sheet', [FinanceReportController::class, 'balanceSheet']);
        Route::get('finance-reports/ar-aging',      [FinanceReportController::class, 'arAging']);
        Route::get('finance-reports/ap-aging',      [FinanceReportController::class, 'apAging']);
        Route::get('finance-reports/stock-valuation', [FinanceReportController::class, 'stockValuation']);
        Route::get('finance-reports/cash-flow',     [FinanceReportController::class, 'cashFlow']);
        Route::get('finance-reports/monthly-trend', [FinanceReportController::class, 'monthlyTrend']);

        // Bank Reconciliation
        Route::post('bank-reconciliations/{bankReconciliation}/import-statement', [BankReconciliationController::class, 'importStatement']);
        Route::post('bank-reconciliations/{bankReconciliation}/clear-statement',  [BankReconciliationController::class, 'clearStatement']);
        Route::post('bank-reconciliations/{bankReconciliation}/auto-match',       [BankReconciliationController::class, 'autoMatch']);
        Route::post('bank-reconciliations/{bankReconciliation}/complete',         [BankReconciliationController::class, 'complete']);
        Route::post('bank-reconciliations/{bankReconciliation}/reopen',           [BankReconciliationController::class, 'reopen']);
        Route::patch('bank-reconciliations/{bankReconciliation}/lines/{line}/match',   [BankReconciliationController::class, 'matchLine']);
        Route::patch('bank-reconciliations/{bankReconciliation}/lines/{line}/unmatch', [BankReconciliationController::class, 'unmatchLine']);
        Route::apiResource('bank-reconciliations', BankReconciliationController::class);

        // ── Semi-static read endpoints — clients may cache for 60 s ──────────
        // CDN NOTE (Task 14): In production, put Cloudflare (free tier) in front
        // of the production domain. It will honour these Cache-Control headers and
        // serve cached responses from an edge node in Nairobi/Johannesburg,
        // cutting round-trip latency for East-Africa clients from ~120 ms to ~15 ms.
        Route::middleware('cache.headers.api:60')->group(function () {
            Route::get('machines/map', [MachineController::class, 'map']);
            Route::get('revenue/summary', [RevenueController::class, 'summary']);
            Route::get('revenue/by-hospital', [RevenueController::class, 'byHospital']);
            Route::get('reports', [ReportController::class, 'index']);
        });

        // Machines (map is registered above in cache group)
        Route::apiResource('machines', MachineController::class);
        Route::post('machines/{machine}/sign-off', [MachineController::class, 'signOff']);

        // Hospitals
        Route::apiResource('hospitals', HospitalController::class);

        // Service Tickets
        Route::post('tickets/{ticket}/resolve', [ServiceTicketController::class, 'resolve']);
        Route::post('tickets/{ticket}/acknowledge', [ServiceTicketController::class, 'acknowledge']);
        Route::post('tickets/{ticket}/parts', [ServiceTicketController::class, 'addPart']);
        Route::post('tickets/{ticket}/checklist/{item}', [ServiceTicketController::class, 'toggleChecklist']);
        Route::get('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'index']);
        Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store']);
        Route::delete('tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'destroy']);
        Route::apiResource('tickets', ServiceTicketController::class);

        // Invoices & Revenue
        Route::post('invoices/{invoice}/send',    [InvoiceController::class, 'send']);
        Route::post('invoices/{invoice}/cancel',  [InvoiceController::class, 'cancel']);
        Route::post('invoices/{invoice}/payments',[InvoiceController::class, 'recordPayment']);
        Route::get('invoices/{invoice}/pdf',        [InvoiceController::class, 'pdf']);
        Route::post('invoices/{invoice}/share-link',[InvoiceController::class, 'shareLink']);
        Route::get('invoices/{invoice}/credit-notes',  [CreditNoteController::class, 'index']);
        Route::post('invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store']);
        Route::post('credit-notes/{creditNote}/approve', [CreditNoteController::class, 'approve']);
        Route::post('credit-notes/{creditNote}/apply',   [CreditNoteController::class, 'apply']);
        Route::apiResource('invoices', InvoiceController::class);

        // Sales Leads
        Route::patch('leads/{lead}/stage', [SalesLeadController::class, 'updateStage']);
        Route::apiResource('leads', SalesLeadController::class);

        // Quotations
        Route::post('quotations/{quotation}/send',    [QuotationController::class, 'send']);
        Route::post('quotations/{quotation}/accept',  [QuotationController::class, 'accept']);
        Route::post('quotations/{quotation}/reject',  [QuotationController::class, 'reject']);
        Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert']);
        Route::post('quotations/{quotation}/approve',        [QuotationController::class, 'approve']);
        Route::post('quotations/{quotation}/reject-approval', [QuotationController::class, 'rejectApproval']);
        Route::get('quotations/{quotation}/pdf',        [QuotationController::class, 'pdf']);
        Route::post('quotations/{quotation}/share-link',[QuotationController::class, 'shareLink']);
        Route::apiResource('quotations', QuotationController::class);

        // Sales Orders
        Route::post('sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm']);
        Route::post('sales-orders/{salesOrder}/deliver', [SalesOrderController::class, 'deliver']);
        Route::post('sales-orders/{salesOrder}/cancel',  [SalesOrderController::class, 'cancel']);
        Route::post('sales-orders/{salesOrder}/invoice', [SalesOrderController::class, 'createInvoice']);
        Route::post('sales-orders/{salesOrder}/approve',        [SalesOrderController::class, 'approve']);
        Route::post('sales-orders/{salesOrder}/reject-approval', [SalesOrderController::class, 'rejectApproval']);
        Route::apiResource('sales-orders', SalesOrderController::class)->only(['index', 'store', 'show']);

        // POS — one-shot counter sale (create + confirm + deliver + invoice + pay)
        Route::post('pos/checkout', [PosController::class, 'checkout']);

        // Locations
        Route::apiResource('locations', LocationController::class);

        // Categories
        Route::apiResource('categories', CategoryController::class);

        // Inventory
        Route::patch('inventory/{inventoryItem}/adjust', [InventoryController::class, 'adjust']);
        Route::get('inventory/{inventoryItem}/history', [InventoryController::class, 'history']);
        Route::get('inventory/{inventoryItem}/movements',  [StockMovementController::class, 'index']);
        Route::get('inventory/{inventoryItem}/batches',    [BatchLotController::class, 'index']);
        Route::post('inventory/{inventoryItem}/batches',   [BatchLotController::class, 'store']);
        Route::put('inventory/{inventoryItem}/batches/{batchLot}',    [BatchLotController::class, 'update']);
        Route::delete('inventory/{inventoryItem}/batches/{batchLot}', [BatchLotController::class, 'destroy']);
        Route::get('inventory/{inventoryItem}/serials',    [SerialNumberController::class, 'index']);
        Route::post('inventory/{inventoryItem}/serials',   [SerialNumberController::class, 'store']);
        Route::put('inventory/{inventoryItem}/serials/{serialNumber}',    [SerialNumberController::class, 'update']);
        Route::delete('inventory/{inventoryItem}/serials/{serialNumber}', [SerialNumberController::class, 'destroy']);
        Route::post('inventory/{inventoryItem}/images',    [InventoryController::class, 'uploadImage']);
        Route::delete('inventory/{inventoryItem}/images/{image}', [InventoryController::class, 'deleteImage']);
        Route::post('inventory/{inventoryItem}/documents', [InventoryController::class, 'uploadDocument']);
        Route::delete('inventory/{inventoryItem}/documents/{document}', [InventoryController::class, 'deleteDocument']);
        Route::post('inventory/quick-create', [InventoryController::class, 'quickCreate']);
        Route::apiResource('inventory', InventoryController::class);

        // Stock Movements (global — filterable by item)
        Route::get('stock-movements',  [StockMovementController::class, 'index']);
        Route::post('stock-movements', [StockMovementController::class, 'store']);

        // Stock Levels (global — flat, filterable list across items x locations)
        Route::get('stock-levels', [StockLevelController::class, 'index']);

        // Suppliers
        Route::post('suppliers/{supplier}/items',                  [SupplierController::class, 'addItem']);
        Route::delete('suppliers/{supplier}/items/{inventoryItem}', [SupplierController::class, 'removeItem']);
        Route::apiResource('suppliers', SupplierController::class);

        // Purchase Requisitions
        Route::post('requisitions/{purchaseRequisition}/submit',  [PurchaseRequisitionController::class, 'submit']);
        Route::post('requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve']);
        Route::post('requisitions/{purchaseRequisition}/reject',  [PurchaseRequisitionController::class, 'reject']);
        Route::apiResource('requisitions', PurchaseRequisitionController::class);

        // Purchase Orders
        Route::post('purchase-orders/{purchaseOrder}/submit-for-approval',     [PurchaseOrderController::class, 'submitForApproval']);
        Route::post('purchase-orders/{purchaseOrder}/approve-sales-manager',   [PurchaseOrderController::class, 'approveSalesManager']);
        Route::post('purchase-orders/{purchaseOrder}/reject-sales-manager',    [PurchaseOrderController::class, 'rejectSalesManager']);
        Route::post('purchase-orders/{purchaseOrder}/approve-director-review', [PurchaseOrderController::class, 'approveDirectorReview']);
        Route::post('purchase-orders/{purchaseOrder}/reject-director-review',  [PurchaseOrderController::class, 'rejectDirectorReview']);
        Route::post('purchase-orders/{purchaseOrder}/initiate-payment',        [PurchaseOrderController::class, 'initiatePayment']);
        Route::post('purchase-orders/{purchaseOrder}/approve-director-final',  [PurchaseOrderController::class, 'approveDirectorFinal']);
        Route::post('purchase-orders/{purchaseOrder}/reject-director-final',   [PurchaseOrderController::class, 'rejectDirectorFinal']);
        Route::post('purchase-orders/{purchaseOrder}/send',    [PurchaseOrderController::class, 'send']);
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
        Route::post('purchase-orders/{purchaseOrder}/cancel',  [PurchaseOrderController::class, 'cancel']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);

        // Contacts (CRM)
        Route::post('contacts/{contact}/interactions', [ContactController::class, 'addInteraction']);
        Route::apiResource('contacts', ContactController::class);

        // Staff
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::get('staff/{user}', [StaffController::class, 'show']);
        Route::put('staff/{user}', [StaffController::class, 'update']);
        Route::delete('staff/{user}', [StaffController::class, 'destroy']);
        Route::patch('staff/{user}/avail_status', [StaffController::class, 'updateAvailStatus']);
        Route::post('staff/{user}/reset-password', [StaffController::class, 'resetPassword']);

        // Contracts
        Route::get('staff/{user}/contracts', [ContractController::class, 'index']);
        Route::post('staff/{user}/contracts', [ContractController::class, 'store']);
        Route::post('contracts/{contract}/renew', [ContractController::class, 'renew']);
        Route::post('contracts/{contract}/end', [ContractController::class, 'end']);
        Route::post('contracts/{contract}/resign', [ContractController::class, 'resign']);
        Route::post('contracts/{contract}/document', [ContractController::class, 'uploadDocument']);
        Route::post('contracts/{contract}/allowances', [ContractController::class, 'addAllowance']);
        Route::delete('contracts/{contract}/allowances/{allowance}', [ContractController::class, 'removeAllowance']);
        Route::get('staff/{user}/payroll-history', [PayrollController::class, 'historyForUser']);

        // Disciplinary cases
        Route::get('staff/{user}/disciplinary-cases', [DisciplinaryCaseController::class, 'index']);
        Route::post('staff/{user}/disciplinary-cases', [DisciplinaryCaseController::class, 'store']);
        Route::post('disciplinary-cases/{disciplinaryCase}/notes', [DisciplinaryCaseController::class, 'addNote']);
        Route::post('disciplinary-cases/{disciplinaryCase}/advance', [DisciplinaryCaseController::class, 'advanceStage']);
        Route::post('disciplinary-cases/{disciplinaryCase}/close', [DisciplinaryCaseController::class, 'close']);

        // Career progression
        Route::get('staff/{user}/position-changes', [PositionChangeController::class, 'index']);
        Route::post('staff/{user}/position-changes', [PositionChangeController::class, 'store']);

        // HR reports
        Route::get('hr-reports/org-chart', [HrReportController::class, 'orgChart']);
        Route::get('hr-reports/turnover', [HrReportController::class, 'turnover']);
        Route::get('hr-reports/headcount', [HrReportController::class, 'headcountBreakdown']);
        Route::get('hr-reports/staff-directory', [HrReportController::class, 'staffDirectory']);
        Route::get('hr-reports/leave-balances', [HrReportController::class, 'leaveBalances']);
        Route::get('hr-reports/leave-calendar', [HrReportController::class, 'leaveCalendar']);
        Route::get('hr-reports/recruitment-summary', [HrReportController::class, 'recruitmentSummary']);
        Route::get('hr-reports/contracts-expiring', [HrReportController::class, 'contractsExpiring']);
        Route::get('hr-reports/contracts-summary', [HrReportController::class, 'contractsSummary']);
        Route::get('hr-reports/disciplinary-summary', [HrReportController::class, 'disciplinarySummary']);
        Route::get('hr-reports/career-progressions', [HrReportController::class, 'careerProgressions']);
        Route::get('hr-reports/export-pdf-link', [HrReportController::class, 'exportPdfLink']);

        // Recruitment
        Route::get('vacancies', [VacancyController::class, 'index']);
        Route::post('vacancies', [VacancyController::class, 'store']);
        Route::put('vacancies/{vacancy}', [VacancyController::class, 'update']);
        Route::get('vacancies/{vacancy}/applications', [ApplicationController::class, 'index']);
        Route::post('vacancies/{vacancy}/applications', [ApplicationController::class, 'store']);
        Route::get('applicants', [ApplicantController::class, 'index']);
        Route::post('applicants', [ApplicantController::class, 'store']);
        Route::get('applicants/{applicant}', [ApplicantController::class, 'show']);
        Route::put('applicants/{applicant}', [ApplicantController::class, 'update']);
        Route::post('applicants/{applicant}/cv', [ApplicantController::class, 'uploadCv']);
        Route::put('applications/{application}/stage', [ApplicationController::class, 'updateStage']);
        Route::post('applications/{application}/interviews', [InterviewController::class, 'store']);
        Route::put('interviews/{interview}', [InterviewController::class, 'update']);

        // Payroll — data model + manual entry this round, see PayrollController.
        Route::get('payroll-runs', [PayrollController::class, 'index']);
        Route::post('payroll-runs', [PayrollController::class, 'store']);
        Route::get('payroll-runs/{payrollRun}', [PayrollController::class, 'show']);
        Route::get('payroll-runs/{payrollRun}/eligible-staff', [PayrollController::class, 'eligibleStaff']);
        Route::post('payroll-runs/{payrollRun}/items', [PayrollController::class, 'upsertItem']);
        Route::delete('payroll-runs/{payrollRun}/items/{item}', [PayrollController::class, 'destroyItem']);
        Route::post('payroll-runs/{payrollRun}/review', [PayrollController::class, 'review']);
        Route::post('payroll-runs/{payrollRun}/approve', [PayrollController::class, 'approve']);
        Route::post('payroll-runs/{payrollRun}/mark-paid', [PayrollController::class, 'markPaid']);
        Route::get('staff/{user}/salary-adjustments', [SalaryAdjustmentController::class, 'index']);
        Route::post('staff/{user}/salary-adjustments', [SalaryAdjustmentController::class, 'store']);
        Route::post('staff/{user}/salary-adjustments/{salaryAdjustment}/approve', [SalaryAdjustmentController::class, 'approve']);

        // Attendance — manual marking is the primary path; import is a
        // best-effort bulk path, see AttendanceController.
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::post('attendance/mark', [AttendanceController::class, 'mark']);
        Route::post('attendance/bulk-mark', [AttendanceController::class, 'bulkMark']);
        Route::post('attendance/import', [AttendanceController::class, 'import']);
        Route::get('attendance/imports', [AttendanceController::class, 'imports']);

        // Positions
        Route::get('positions', [PositionController::class, 'index']);
        Route::post('positions', [PositionController::class, 'store']);
        Route::put('positions/{position}', [PositionController::class, 'update']);
        Route::delete('positions/{position}', [PositionController::class, 'destroy']);

        // Tasks (general — separate from service tickets)
        Route::apiResource('tasks', TaskController::class)->except(['show']);

        // Leave requests
        Route::get('leave-requests', [LeaveController::class, 'index']);
        Route::post('leave-requests', [LeaveController::class, 'store']);
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject']);
        Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);

        // Leave types, balances, holiday calendar, HR settings
        Route::get('leave-types', [LeaveTypeController::class, 'index']);
        Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update']);
        Route::get('leave-balances', [LeaveBalanceController::class, 'index']);
        Route::apiResource('public-holidays', PublicHolidayController::class)->except(['show']);
        Route::get('hr-settings', [HrSettingController::class, 'index']);
        Route::put('hr-settings', [HrSettingController::class, 'update']);

        // Late arrivals
        Route::get('late-arrivals', [LateArrivalController::class, 'index']);
        Route::post('late-arrivals', [LateArrivalController::class, 'store']);

        // Stock-out requests (approval workflow)
        Route::get('stock-out-requests', [StockOutRequestController::class, 'index']);
        Route::post('stock-out-requests', [StockOutRequestController::class, 'store']);
        Route::post('stock-out-requests/{stockOutRequest}/approve', [StockOutRequestController::class, 'approve']);
        Route::post('stock-out-requests/{stockOutRequest}/reject', [StockOutRequestController::class, 'reject']);
        Route::post('stock-out-requests/{stockOutRequest}/cancel', [StockOutRequestController::class, 'cancel']);

        // Per-diem requests (approval workflow)
        Route::get('per-diem-requests', [PerDiemController::class, 'index']);
        Route::post('per-diem-requests', [PerDiemController::class, 'store']);
        Route::post('per-diem-requests/{perDiemRequest}/approve-team-lead', [PerDiemController::class, 'approveTeamLead']);
        Route::post('per-diem-requests/{perDiemRequest}/reject-team-lead', [PerDiemController::class, 'rejectTeamLead']);
        Route::post('per-diem-requests/{perDiemRequest}/approve', [PerDiemController::class, 'approve']);
        Route::post('per-diem-requests/{perDiemRequest}/reject', [PerDiemController::class, 'reject']);
        Route::post('per-diem-requests/{perDiemRequest}/initiate-payment', [PerDiemController::class, 'initiatePayment']);
        Route::post('per-diem-requests/{perDiemRequest}/mark-paid', [PerDiemController::class, 'markPaid']);
        Route::post('per-diem-requests/{perDiemRequest}/cancel', [PerDiemController::class, 'cancel']);

        // Part cannibalizations (parts pulled from stocked units to fix field machines)
        Route::get('part-cannibalizations', [PartCannibalizationController::class, 'index']);
        Route::post('part-cannibalizations/{partCannibalization}/order-replacement', [PartCannibalizationController::class, 'orderReplacement']);
        Route::post('part-cannibalizations/{partCannibalization}/resolve', [PartCannibalizationController::class, 'resolve']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);

        // Email accounts (IMAP/SMTP config)
        Route::post('email-accounts/{emailAccount}/test', [EmailAccountController::class, 'test']);
        Route::apiResource('email-accounts', EmailAccountController::class);

        // Email (must be ordered: specific paths before {id})
        Route::get('emails/inbox',        [EmailController::class, 'inbox']);
        Route::get('emails/sent',         [EmailController::class, 'sent']);
        Route::get('emails/drafts',       [EmailController::class, 'drafts']);
        Route::get('emails/unread-count', [EmailController::class, 'unreadCount']);
        Route::get('emails/folders',      [EmailController::class, 'folders']);
        Route::get('emails/folder/{folderName}', [EmailController::class, 'folder']);
        Route::post('emails/sync',        [EmailController::class, 'sync']);
        Route::post('emails/compose',     [EmailController::class, 'compose']);
        Route::get('emails/{syncedEmail}',                    [EmailController::class, 'show']);
        Route::post('emails/{syncedEmail}/reply',             [EmailController::class, 'reply']);
        Route::post('emails/{syncedEmail}/forward',           [EmailController::class, 'forward']);
        Route::patch('emails/{syncedEmail}/read',             [EmailController::class, 'markRead']);
        Route::patch('emails/{syncedEmail}/flag',             [EmailController::class, 'flag']);
        Route::delete('emails/{syncedEmail}',                 [EmailController::class, 'destroy']);
    });
});
