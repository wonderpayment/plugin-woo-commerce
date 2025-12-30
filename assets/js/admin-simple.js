// Wonder Payments Simple Admin JS
console.log("=== Wonder Payments Simple Admin JS Loaded ===");

jQuery(document).ready(function($) {
    console.log("DOM ready for Wonder Payments");
    
    // 检查全局对象
    if (typeof wonder_payments_admin === "undefined") {
        console.error("ERROR: wonder_payments_admin object is not defined!");
        console.error("This means the script was not properly localized.");
        console.error("Possible causes:");
        console.error("1. Script is being cached (try Ctrl+F5)");
        console.error("2. There's a JavaScript error on the page");
        console.error("3. The script is not being enqueued on this page");
        
        // 显示错误提示
        if ($('#wonder-generate-message').length) {
            $('#wonder-generate-message').html(
                '<span style="color: red;">❌ JavaScript configuration error. Please refresh the page (Ctrl+F5).</span>'
            ).addClass('error').show();
        }
        return;
    }
    
    console.log("wonder_payments_admin object found");
    console.log("AJAX URL:", wonder_payments_admin.ajax_url);
    console.log("Generate nonce:", wonder_payments_admin.generate_nonce ? "Set" : "Missing");
    console.log("Test nonce:", wonder_payments_admin.test_nonce ? "Set" : "Missing");
    console.log("Localized strings:", wonder_payments_admin.strings);
    
    // 检查按钮是否存在
    console.log("Generate button found:", $('#wonder-generate-keys').length);
    console.log("Test button found:", $('#wonder-test-config').length);
    console.log("Private key textarea found:", $('#wonder-private-key').length);
    console.log("Public key textarea found:", $('#wonder-public-key').length);
    
    // 如果按钮存在但事件未绑定，尝试绑定（作为备用）
    if ($('#wonder-generate-keys').length > 0) {
        console.log("Ensuring generate button has event handler...");
        $('#wonder-generate-keys').off('click.wonder-backup').on('click.wonder-backup', function(e) {
            console.log("Backup generate handler triggered");
            e.preventDefault();
            alert("Please refresh the page (Ctrl+F5) to ensure proper JavaScript loading.");
        });
    }
    
    if ($('#wonder-test-config').length > 0) {
        console.log("Ensuring test button has event handler...");
        $('#wonder-test-config').off('click.wonder-backup').on('click.wonder-backup', function(e) {
            console.log("Backup test handler triggered");
            e.preventDefault();
            alert("Please refresh the page (Ctrl+F5) to ensure proper JavaScript loading.");
        });
    }
    
    console.log("=== Wonder Payments Simple Admin JS Initialization Complete ===");
});