<?php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/ntn_erp/assets/js/main.js"></script>
    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/kpi_popup.php')) include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/kpi_popup.php'; ?>
</body>
</html>
