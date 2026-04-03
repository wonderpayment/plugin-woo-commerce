(function($) {
    'use strict';

    var config = window.wonderPaymentsAdmin || {};
    var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
    var nonces = config.nonces || {};
    var urls = config.urls || {};
    var strings = Object.assign({}, {
        enterFields: 'Please enter App ID and Private Key first.',
        errorPrefix: 'Error:',
        failedToLoadContent: 'Failed to load content',
        hideSuggestions: 'Suggestions hidden',
        deactivateConfirm: 'Are you sure you want to delete the App ID and all configuration data? This cannot be undone.',
        deactivateSuccess: 'All configuration data was deleted successfully.',
        deactivateError: 'Deletion failed, please try again.',
        syncing: 'Syncing...',
        syncSuccess: 'Sync successful!',
        syncFailed: 'Sync failed:',
        save: 'Save',
        saving: 'Saving...',
        create: 'Create',
        creating: 'Creating...',
        created: 'Created',
        scanSuccess: 'Scanned Successfully',
        loginFirst: 'Please scan QR code to login first',
        loadingBusinesses: 'Loading business list...',
        noBusiness: 'No business found',
        loadBusinessFailed: 'Failed to load business list',
        generateLoading: 'Generating key pair and app_id...'
    }, config.strings || {});
    var gatewayStatus = Object.assign({}, {
        enabled: 'no',
        appId: '',
        privateKey: ''
    }, config.gatewayStatus || {});

    function postAjax(data, options) {
        var requestOptions = Object.assign({}, {
            url: ajaxUrl,
            method: 'POST',
            data: data
        }, options || {});

        return $.ajax(requestOptions);
    }

    function getActionName(settings) {
        if (!settings || !settings.data) {
            return '';
        }

        if (typeof settings.data === 'string') {
            var match = settings.data.match(/(?:^|&)action=([^&]+)/);
            return match ? decodeURIComponent(match[1]) : '';
        }

        return settings.data.action || '';
    }

    function escapeHtml(value) {
        return $('<div/>').text(value || '').html();
    }

    function showInlineMessage($target, type, text) {
        var color = type === 'success' ? 'green' : 'red';
        var icon = type === 'success' ? 'OK' : '!';
        $target
            .html('<span style="color: ' + color + ';">' + icon + ' ' + escapeHtml(text) + '</span>')
            .removeClass('success error')
            .addClass(type)
            .show();
    }

    function clearPolling() {
        if (window.wonderPaymentsPollInterval) {
            clearInterval(window.wonderPaymentsPollInterval);
            window.wonderPaymentsPollInterval = null;
        }
    }

    function closeWonderModal() {
        clearPolling();
        $('#wonder-modal-body').empty();
        $('#wonder-settings-modal').fadeOut(300);
    }

    function hideSuggestions(showNotice) {
        $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();
        localStorage.setItem('wonder_payments_suggestions_hidden', 'true');

        if (showNotice) {
            window.alert(strings.hideSuggestions);
        }
    }

    function findGatewayRow() {
        var $row = $('#wonder_payments');

        if ($row.length) {
            return $row.first();
        }

        return $('.woocommerce-list__item, tr').filter(function() {
            var $el = $(this);
            var text = $.trim($el.text());
            return $el.attr('id') === 'wonder_payments' ||
                $el.data('id') === 'wonder_payments' ||
                $el.data('gateway-id') === 'wonder_payments' ||
                text.indexOf('Wonder Payment') !== -1;
        }).first();
    }

    function updateStatusBadge() {
        var $row = findGatewayRow();
        if (!$row.length) {
            return;
        }

        var $title = $row.find('h1, h2, h3, h4, h5, h6, .woocommerce-list__item-title, .woocommerce-list__item-name, .components-card__header-title').first();
        if (!$title.length) {
            return;
        }

        $title.find('.wonder-payments-status-badge').remove();

        if (gatewayStatus.enabled === 'yes' && (!gatewayStatus.appId || !gatewayStatus.privateKey)) {
            $title.append(' <span class="wonder-payments-status-badge wonder-payments-status-action-needed">Action needed</span>');
        }
    }

    function reloadConfiguration() {
        if (!nonces.config) {
            return $.Deferred().resolve().promise();
        }

        return postAjax({
            action: 'wonder_payments_get_config',
            security: nonces.config
        }).done(function(response) {
            if (response && response.success && response.data) {
                gatewayStatus.enabled = response.data.enabled || 'no';
                gatewayStatus.appId = response.data.app_id || '';
                gatewayStatus.privateKey = response.data.private_key || '';
                updateStatusBadge();
            }
        });
    }

    function handleDeactivate() {
        if (!window.confirm(strings.deactivateConfirm)) {
            return;
        }

        postAjax({
            action: 'wonder_payments_deactivate',
            security: nonces.deactivate
        }).done(function(response) {
            if (response && response.success) {
                localStorage.removeItem('wonder_access_token');
                localStorage.removeItem('wonder_business_id');
                localStorage.removeItem('wonder_selected_business_id');
                localStorage.removeItem('wonder_selected_business_name');
                localStorage.removeItem('wonder_skip_business_list_load');
                localStorage.removeItem('wonder_app_id');
                localStorage.removeItem('wonder_private_key');
                localStorage.removeItem('wonder_public_key');
                localStorage.removeItem('wonder_webhook_key');
                window.alert(strings.deactivateSuccess);
                closeWonderModal();
                reloadConfiguration();
                return;
            }

            var message = response && response.data && response.data.message ? response.data.message : strings.deactivateError;
            window.alert(message);
        }).fail(function() {
            window.alert(strings.deactivateError);
        });
    }

    function injectEllipsisMenu() {
        var $menu = $('.woocommerce-ellipsis-menu__content').first();
        if (!$menu.length || $menu.find('.wonder-payments-menu-item').length) {
            return;
        }

        var items = [
            '<div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item"><button type="button" class="components-button wonder-payments-menu-action" data-url="' + escapeHtml(urls.pricing || '') + '">See pricing & fees</button></div>',
            '<div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item"><button type="button" class="components-button wonder-payments-menu-action" data-url="' + escapeHtml(urls.support || '') + '">Get support</button></div>',
            '<div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item"><button type="button" class="components-button wonder-payments-menu-action" data-url="' + escapeHtml(urls.docs || '') + '">View documentation</button></div>',
            '<div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item"><button type="button" class="components-button wonder-payments-menu-action" data-url="' + escapeHtml(urls.onboarding || '') + '">Onboarding</button></div>',
            '<div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item"><button type="button" class="components-button components-button__danger wonder-payments-menu-action" data-action="deactivate">Deactivate</button></div>'
        ];

        $menu.append(items.join(''));
    }

    function bindManageButton() {
        var $row = findGatewayRow();
        if (!$row.length) {
            return false;
        }

        var $manageLink = $row.find('a[href*="section=wonder_payments"]').first();
        var $manageButton = $manageLink.length ? $manageLink : $row.find('[data-gateway-id="wonder_payments"], .components-button.is-secondary, a').first();

        if (!$manageButton.length) {
            return false;
        }

        $manageButton.attr('href', '#');
        $manageButton.off('click.wonderPaymentsManage').on('click.wonderPaymentsManage', openWonderPaymentsModal);

        return true;
    }

    function openWonderPaymentsModal(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        var $modalBody = $('#wonder-modal-body');

        $('#wonder-settings-modal').fadeIn(300);
        $modalBody.html('<div class="wonder-loading"><span class="spinner is-active"></span> Loading...</div>');

        postAjax({
            action: 'wonder_payments_load_modal_content',
            security: nonces.modal
        }).done(function(response) {
            if (response && response.success && response.data && response.data.content) {
                $modalBody.html(response.data.content);
                initializeModalContent();
                return;
            }

            $modalBody.html('<p class="error">' + escapeHtml(strings.failedToLoadContent) + '</p>');
        }).fail(function() {
            $modalBody.html('<p class="error">' + escapeHtml(strings.failedToLoadContent) + '</p>');
        });
    }

    function syncHiddenKeys() {
        var privateValue = $('#wonder-private-key-display').val() || '';
        var publicValue = $('#wonder-generated-public-key-display').val() || '';

        $('textarea[name="woocommerce_wonder_payments_private_key"]').val(privateValue);
        $('textarea[name="woocommerce_wonder_payments_generated_public_key"]').val(publicValue);
    }

    function initGatewaySettings() {
        if (!config.isGatewaySection) {
            return;
        }

        $(document)
            .off('click.wonderGenerateKeys')
            .on('click.wonderGenerateKeys', '#wonder-generate-keys', function(event) {
                event.preventDefault();

                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $message = $('#wonder-generate-message');

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $message.empty();
                $message.removeClass('success error');
                $message.show();

                postAjax({
                    action: 'wonder_generate_keys',
                    security: nonces.generateKeys
                }).done(function(response) {
                    if (response && response.success && response.data) {
                        $('#wonder-private-key-display').val(response.data.private_key || '');
                        $('#wonder-generated-public-key-display').val(response.data.public_key || '');
                        syncHiddenKeys();
                        showInlineMessage($message, 'success', response.data.message || '');
                        return;
                    }

                    var errorMessage = response && response.data && response.data.message ? response.data.message : strings.errorPrefix;
                    showInlineMessage($message, 'error', errorMessage);
                }).fail(function(xhr, status, error) {
                    showInlineMessage($message, 'error', strings.errorPrefix + ' ' + (error || status));
                }).always(function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
            });

        $(document)
            .off('click.wonderTestConfig')
            .on('click.wonderTestConfig', '#wonder-test-config', function(event) {
                event.preventDefault();

                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $message = $('#wonder-action-message');
                var appId = $('input[name="woocommerce_wonder_payments_app_id"]').val() || '';
                var privateKey = $('#wonder-private-key-display').val() || '';
                var webhookKey = $('textarea[name="woocommerce_wonder_payments_webhook_public_key"]').val() || '';
                var environment = $('input[name="woocommerce_wonder_payments_environment"]').is(':checked') ? 'yes' : 'no';

                if (!appId || !privateKey) {
                    showInlineMessage($message, 'error', strings.enterFields);
                    return;
                }

                syncHiddenKeys();

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $message.empty();
                $message.removeClass('success error');
                $message.show();

                postAjax({
                    action: 'wonder_test_config',
                    security: nonces.testConfig,
                    app_id: appId,
                    private_key: privateKey,
                    webhook_key: webhookKey,
                    environment: environment
                }).done(function(response) {
                    if (response && response.success) {
                        showInlineMessage($message, 'success', response.data.message || '');
                        return;
                    }

                    var errorMessage = response && response.data && response.data.message ? response.data.message : strings.errorPrefix;
                    showInlineMessage($message, 'error', errorMessage);
                }).fail(function(xhr, status, error) {
                    showInlineMessage($message, 'error', strings.errorPrefix + ' ' + (error || status));
                }).always(function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
            });

        $(document)
            .off('submit.wonderSettingsSubmit')
            .on('submit.wonderSettingsSubmit', 'form', function() {
                syncHiddenKeys();
            });
    }

    function initOrderSync() {
        if (!config.isOrderScreen) {
            return;
        }

        $(document)
            .off('click.wonderOrderSync')
            .on('click.wonderOrderSync', '#wonder-sync-status-btn', function() {
                var $button = $(this);
                var $message = $('#wonder-sync-status-message');
                var orderId = $button.data('order-id');
                var nonce = $button.data('nonce');

                $button.prop('disabled', true);
                $message.text(strings.syncing).css('color', '');

                postAjax({
                    action: 'wonder_sync_order_status',
                    order_id: orderId,
                    security: nonce
                }).done(function(response) {
                    if (response && response.success) {
                        $message.text(strings.syncSuccess).css('color', 'green');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                        return;
                    }

                    var errorMessage = response && response.data ? response.data : 'Unknown error';
                    $message.text(strings.syncFailed + ' ' + errorMessage).css('color', 'red');
                    $button.prop('disabled', false);
                }).fail(function(xhr, status, error) {
                    $message.text(strings.syncFailed + ' ' + (error || status)).css('color', 'red');
                    $button.prop('disabled', false);
                });
            });
    }

    function initGatewayListPage() {
        if (!config.isGatewayList) {
            return;
        }

        if (localStorage.getItem('wonder_payments_suggestions_hidden') === 'true') {
            hideSuggestions(false);
        }

        $(document)
            .off('click.wonderPaymentsManage')
            .on('click.wonderPaymentsManage', '.wonder-payments-manage-link, [data-gateway-id="wonder_payments"], a[href*="section=wonder_payments"]', openWonderPaymentsModal);

        $(document)
            .off('click.wonderPaymentsEllipsis')
            .on('click.wonderPaymentsEllipsis', '.gridicons-ellipsis', function() {
                var $row = $(this).closest('.woocommerce-list__item');
                if ($row.attr('id') === 'wonder_payments') {
                    setTimeout(injectEllipsisMenu, 50);
                }
            });

        $(document)
            .off('click.wonderPaymentsMenuAction')
            .on('click.wonderPaymentsMenuAction', '.wonder-payments-menu-action', function(event) {
                event.preventDefault();

                var $button = $(this);
                var targetUrl = $button.data('url');
                var action = $button.data('action');

                if (targetUrl) {
                    window.open(targetUrl, '_blank', 'noopener');
                    return;
                }

                if (action === 'deactivate') {
                    handleDeactivate();
                }
            });

        $(document)
            .off('click.wonderPaymentsHideSuggestions')
            .on('click.wonderPaymentsHideSuggestions', '.hide-suggestions, .wonder-payments-hide-suggestions', function(event) {
                event.preventDefault();
                hideSuggestions(true);
            });

        $(document)
            .off('click.wonderPaymentsCloseModal')
            .on('click.wonderPaymentsCloseModal', '#close-wonder-modal', function(event) {
                event.preventDefault();
                closeWonderModal();
                reloadConfiguration();
            });

        $('#wonder-settings-modal')
            .off('click.wonderPaymentsBackdrop')
            .on('click.wonderPaymentsBackdrop', function(event) {
                if (event.target === this) {
                    closeWonderModal();
                    reloadConfiguration();
                }
            });

        $(document)
            .off('keydown.wonderPaymentsModal')
            .on('keydown.wonderPaymentsModal', function(event) {
                if (event.key === 'Escape' && $('#wonder-settings-modal').is(':visible')) {
                    closeWonderModal();
                    reloadConfiguration();
                }
            });

        $(document)
            .off('change.wonderPaymentsToggle')
            .on('change.wonderPaymentsToggle', '.woocommerce-input-toggle', function() {
                var $row = $(this).closest('.woocommerce-list__item');
                if ($row.attr('id') !== 'wonder_payments') {
                    return;
                }

                setTimeout(function() {
                    gatewayStatus.enabled = $row.find('.woocommerce-input-toggle').hasClass('woocommerce-input-toggle--enabled') ? 'yes' : 'no';
                    updateStatusBadge();
                }, 0);
            });

        $(document)
            .off('ajaxComplete.wonderPayments')
            .on('ajaxComplete.wonderPayments', function(event, xhr, settings) {
                var action = getActionName(settings);
                if ($.inArray(action, ['wonder_payments_save_selected_business', 'wonder_payments_save_settings', 'wonder_payments_generate_app_id', 'wonder_payments_clear_all']) !== -1) {
                    setTimeout(reloadConfiguration, 500);
                }
            });

        bindManageButton();

        if (window.MutationObserver) {
            var observer = new MutationObserver(function() {
                bindManageButton();
                updateStatusBadge();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        setTimeout(updateStatusBadge, 500);
        setTimeout(updateStatusBadge, 1500);
    }

    function initializeModalContent() {
        var $container = $('#wonder-modal-body .modal-container').first();

        if (!$container.length || $container.data('wonderPaymentsInitialized')) {
            return;
        }

        $container.data('wonderPaymentsInitialized', true);

        function setMenuLock(locked) {
            $container.data('wonderPaymentsMenuLocked', locked ? '1' : '0');
            $container.find('.menu-item[data-tab="scan"], .menu-item[data-tab="business"]')
                .toggleClass('locked', !!locked)
                .attr('aria-disabled', locked ? 'true' : 'false');
        }

        function isMenuLocked() {
            return $container.data('wonderPaymentsMenuLocked') === '1';
        }

        function clearSessionStorage() {
            localStorage.removeItem('wonder_access_token');
            localStorage.removeItem('wonder_business_id');
            localStorage.removeItem('wonder_selected_business_id');
            localStorage.removeItem('wonder_selected_business_name');
            localStorage.removeItem('wonder_skip_business_list_load');
            localStorage.removeItem('wonder_app_id');
            localStorage.removeItem('wonder_private_key');
            localStorage.removeItem('wonder_public_key');
            localStorage.removeItem('wonder_webhook_key');
        }

        function activateTab(tabId) {
            if (isMenuLocked() && (tabId === 'scan' || tabId === 'business')) {
                return;
            }

            clearPolling();
            $container.find('.menu-item').removeClass('active');
            $container.find('.content-panel').removeClass('active');
            $container.find('.menu-item[data-tab="' + tabId + '"]').addClass('active');
            $container.find('#panel-' + tabId).addClass('active');

            if (tabId === 'scan') {
                var accessToken = localStorage.getItem('wonder_access_token');
                var businessId = localStorage.getItem('wonder_business_id');

                if (accessToken && businessId) {
                    $container.find('#logout-section').show();
                    $container.find('.qr-code-section').hide();
                    $container.find('.qr-loading').html('<div class="scan-success">OK ' + escapeHtml(strings.scanSuccess) + '</div>').show();
                    return;
                }

                generateQRCode();
            } else if (tabId === 'business') {
                loadBusinessList();
            } else if (tabId === 'activation') {
                loadActivationPage();
            } else if (tabId === 'settings') {
                loadSettings();
            }
        }

        function showScanSuccess() {
            $container.find('.qr-code-placeholder img').hide();
            $container.find('.qr-loading').html('<div class="scan-success">OK ' + escapeHtml(strings.scanSuccess) + '</div>').show();
            $container.find('#logout-section').show();
        }

        function checkExistingConnection() {
            var businessId = localStorage.getItem('wonder_business_id');
            var accessToken = localStorage.getItem('wonder_access_token');

            if (!businessId || !accessToken) {
                return $.Deferred().resolve({
                    success: true,
                    data: {
                        connected: false
                    }
                }).promise();
            }

            return postAjax({
                action: 'wonder_payments_check_connection',
                security: nonces.modal
            }).fail(function() {
                return {
                    success: true,
                    data: {
                        connected: false
                    }
                };
            });
        }

        function renderBusinessList(businesses, existingConnection) {
            var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');
            var connectedBusinessId = existingConnection && existingConnection.business_id ? existingConnection.business_id : null;
            var connectedAppId = existingConnection && existingConnection.app_id ? existingConnection.app_id : null;
            var finalBusinessId = selectedBusinessId || connectedBusinessId;
            var html = '';

            $.each(businesses, function(index, business) {
                if (business && business.business_type === 'Natural Person') {
                    return;
                }

                var status = business.status || 'inactive';
                var statusText = String(status).toUpperCase();
                var statusClass = status === 'Active' ? 'active' : 'inactive';
                var businessName = business.business_name || business.business_dba || 'Business Name';
                var isConnected = finalBusinessId && String(finalBusinessId) === String(business.id);
                var buttonHtml;

                if (status === 'Active') {
                    if (isConnected) {
                        buttonHtml = '<div class="connection-status connected">Connected</div>' + (connectedAppId ? '<div class="app-id-display">App ID: ' + escapeHtml(connectedAppId) + '</div>' : '');
                    } else {
                        buttonHtml = '<button class="choose-btn" data-business-id="' + escapeHtml(business.id) + '" data-business-name="' + escapeHtml(businessName) + '">Choose</button>';
                    }
                } else {
                    buttonHtml = '<div class="status-text">Can not choose this business</div>';
                }

                html += '<div class="business-card ' + statusClass + (isConnected ? ' connected' : '') + '" data-business-id="' + escapeHtml(business.id) + '">' +
                    '<div class="card-header">' + escapeHtml(statusText) + '</div>' +
                    '<div class="card-body"><div class="store-name">' + escapeHtml(businessName) + '</div></div>' +
                    '<div class="card-footer">' + buttonHtml + '</div>' +
                    '</div>';
            });

            $container.find('.cards-container').html(html);
        }

        function loadBusinessList() {
            var accessToken = localStorage.getItem('wonder_access_token');

            if (!accessToken) {
                $container.find('.cards-container').html('<div class="no-business">' + escapeHtml(strings.loginFirst) + '</div>');
                return;
            }

            $container.find('.cards-container').html('<div class="loading-business">' + escapeHtml(strings.loadingBusinesses) + '</div>');

            postAjax({
                action: 'wonder_payments_sdk_get_businesses',
                security: nonces.modal
            }).done(function(response) {
                var businessList = response && response.data && response.data.data ? response.data.data : [];

                if (response && response.success && $.isArray(businessList) && businessList.length) {
                    checkExistingConnection().done(function(connectionResponse) {
                        var existingConnection = connectionResponse && connectionResponse.success && connectionResponse.data && connectionResponse.data.connected ? connectionResponse.data : null;
                        renderBusinessList(businessList, existingConnection);
                    }).fail(function() {
                        renderBusinessList(businessList, null);
                    });
                    return;
                }

                if (response && response.data && response.data.message) {
                    if (String(response.data.message).indexOf('Access token expired') !== -1) {
                        localStorage.removeItem('wonder_access_token');
                        localStorage.removeItem('wonder_business_id');
                    }

                    $container.find('.cards-container').html('<div class="no-business">' + escapeHtml(response.data.message) + '</div>');
                    return;
                }

                $container.find('.cards-container').html('<div class="no-business">' + escapeHtml(strings.noBusiness) + '</div>');
            }).fail(function() {
                $container.find('.cards-container').html('<div class="no-business">' + escapeHtml(strings.loadBusinessFailed) + '</div>');
            });
        }

        function saveSelectedBusiness(businessId, businessName) {
            localStorage.setItem('wonder_selected_business_id', businessId);
            localStorage.setItem('wonder_selected_business_name', businessName);

            postAjax({
                action: 'wonder_payments_save_selected_business',
                security: nonces.modal,
                business_id: businessId,
                business_name: businessName
            }).done(function(response) {
                if (response && response.success) {
                    loadBusinessList();
                    setTimeout(function() {
                        activateTab('activation');
                    }, 300);
                }
            });
        }

        function fillActivationSettings(settingsData) {
            settingsData = settingsData || {};

            $container.find('#app-id-input').val(settingsData.app_id || '');
            $container.find('#private-key-input').val(settingsData.private_key || '');
            $container.find('#public-key-input').val(settingsData.generated_public_key || '');
            $container.find('#webhook-key-input').val(settingsData.webhook_public_key || settingsData.webhook_key || '');

            if (settingsData.app_id) {
                $container.find('#create-app-id-btn').text(strings.created).prop('disabled', true);
                setMenuLock(true);
            } else {
                $container.find('#create-app-id-btn').text(strings.create).prop('disabled', false);
                setMenuLock(false);
            }
        }

        function generateKeyPairOnly(businessId, onSuccess, onError) {
            $container.find('#app-id-input').val('');
            $container.find('#create-app-id-btn').text(strings.create).prop('disabled', false);

            postAjax({
                action: 'wonder_payments_generate_key_pair_only',
                security: nonces.modal,
                business_id: businessId
            }).done(function(response) {
                if (response && response.success) {
                    var keyData = response.data && response.data.data ? response.data.data : (response.data || {});
                    fillActivationSettings(keyData);

                    if (typeof onSuccess === 'function') {
                        onSuccess(keyData);
                    }
                    return;
                }

                if (typeof onError === 'function') {
                    onError(response);
                }
            }).fail(function(xhr, status, error) {
                if (typeof onError === 'function') {
                    onError(error || status);
                }
            });
        }

        function generateAppIdOnly(businessId, businessName, onSuccess, onError) {
            $container.find('#create-app-id-btn').text(strings.creating).prop('disabled', true);

            postAjax({
                action: 'wonder_payments_sdk_create_app_id',
                security: nonces.modal,
                business_id: businessId,
                business_name: businessName
            }).done(function(response) {
                if (response && response.success) {
                    var appId = response.app_id ||
                        (response.data && response.data.app_id) ||
                        (response.data && response.data.data && response.data.data.app_id) || '';
                    var webhookKey = response.webhook_private_key ||
                        (response.data && response.data.webhook_private_key) ||
                        (response.data && response.data.data && response.data.data.webhook_private_key) ||
                        response.webhook_public_key ||
                        (response.data && response.data.webhook_public_key) ||
                        (response.data && response.data.data && response.data.data.webhook_public_key) || '';

                    if (appId) {
                        $container.find('#app-id-input').val(appId);
                        $container.find('#webhook-key-input').val(webhookKey);
                        $container.find('#create-app-id-btn').text(strings.created).prop('disabled', true);
                        setMenuLock(true);

                        if (typeof onSuccess === 'function') {
                            onSuccess(response);
                        }
                        return;
                    }
                }

                $container.find('#create-app-id-btn').text(strings.create).prop('disabled', false);
                if (typeof onError === 'function') {
                    onError(response);
                }
            }).fail(function(xhr, status, error) {
                $container.find('#create-app-id-btn').text(strings.create).prop('disabled', false);
                if (typeof onError === 'function') {
                    onError(error || status);
                }
            });
        }

        function loadActivationPage() {
            var businessId = localStorage.getItem('wonder_selected_business_id');

            if (!businessId) {
                activateTab('business');
                return;
            }

            postAjax({
                action: 'wonder_payments_load_settings',
                security: nonces.modal
            }).done(function(response) {
                var settingsData = response && response.data && response.data.data ? response.data.data : response.data;

                if (settingsData && settingsData.app_id) {
                    fillActivationSettings(settingsData);
                    return;
                }

                generateKeyPairOnly(businessId);
            }).fail(function() {
                generateKeyPairOnly(businessId);
            });
        }

        function loadSettings() {
            setTimeout(function() {
                postAjax({
                    action: 'wonder_payments_load_settings',
                    security: nonces.modal
                }).done(function(response) {
                    var settingsData = response && response.data && response.data.data ? response.data.data : response.data;
                    settingsData = settingsData || {};

                    $container.find('#settings-title').val(settingsData.title || '');
                    $container.find('#settings-description').val(settingsData.description || '');
                    $container.find('#settings-due-date').val(settingsData.due_date || '30');
                    $container.find('#settings-sandbox').attr('data-enabled', settingsData.sandbox_mode === '1' ? 'true' : 'false');
                });
            }, 100);
        }

        function saveSettings(skipRegen) {
            var settingsData = {
                title: $container.find('#settings-title').val(),
                description: $container.find('#settings-description').val(),
                sandbox_mode: $container.find('#settings-sandbox').attr('data-enabled') === 'true' ? '1' : '0',
                due_date: $container.find('#settings-due-date').val(),
                app_id: $container.find('#app-id-input').val(),
                private_key: $container.find('#private-key-input').val(),
                generated_public_key: $container.find('#public-key-input').val(),
                webhook_public_key: $container.find('#webhook-key-input').val()
            };

            $container.find('#save-settings-btn').text(strings.saving).prop('disabled', true);

            postAjax({
                action: 'wonder_payments_save_settings',
                security: nonces.modal,
                settings: settingsData
            }).done(function(response) {
                if (response && response.success) {
                    var envChanged = response.data && response.data.environment_changed;

                    if (envChanged && !skipRegen) {
                        var businessId = localStorage.getItem('wonder_selected_business_id') || localStorage.getItem('wonder_business_id');
                        var businessName = localStorage.getItem('wonder_selected_business_name') || '';

                        $container.find('#app-id-input, #private-key-input, #public-key-input, #webhook-key-input').val('');

                        if (businessId) {
                            generateKeyPairOnly(
                                businessId,
                                function() {
                                    generateAppIdOnly(
                                        businessId,
                                        businessName,
                                        function() {
                                            saveSettings(true);
                                        },
                                        function() {
                                            $container.find('#save-settings-btn').text(strings.save).prop('disabled', false);
                                        }
                                    );
                                },
                                function() {
                                    $container.find('#save-settings-btn').text(strings.save).prop('disabled', false);
                                }
                            );
                            return;
                        }
                    }

                    closeWonderModal();
                    reloadConfiguration();
                }
            }).always(function() {
                $container.find('#save-settings-btn').text(strings.save).prop('disabled', false);
            });
        }

        function generateQRCode() {
            clearPolling();
            $container.find('#logout-section').hide();
            $container.find('.qr-code-section').show();
            $container.find('.qr-loading').text('Loading QR code...').show();
            $container.find('.qr-code-placeholder img').hide();

            postAjax({
                action: 'wonder_payments_sdk_create_qrcode',
                security: nonces.modal
            }).done(function(response) {
                if (!(response && response.success && response.data)) {
                    return;
                }

                var uuid = response.data.uuid;
                var shortUrl = response.data.sUrl;
                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' + encodeURIComponent(shortUrl);
                var $img = $container.find('.qr-code-placeholder img');

                startPolling(uuid);
                $container.find('.qr-loading').hide();
                $img.attr('src', qrUrl).show();
            });
        }

        function startPolling(uuid) {
            clearPolling();
            window.wonderPaymentsCurrentUuid = uuid;

            window.wonderPaymentsPollInterval = window.setInterval(function() {
                $.ajax({
                    url: ajaxUrl,
                    method: 'GET',
                    data: {
                        action: 'wonder_payments_sdk_qrcode_status',
                        uuid: uuid,
                        security: nonces.modal
                    }
                }).done(function(response) {
                    if (!(response && response.success && response.data && response.data.data)) {
                        return;
                    }

                    var data = response.data.data;

                    if (data.is_scan && data.access_token) {
                        if (data.business_id) {
                            localStorage.setItem('wonder_business_id', data.business_id);
                        }

                        localStorage.setItem('wonder_access_token', data.access_token);

                        postAjax({
                            action: 'wonder_payments_sdk_save_access_token',
                            security: nonces.modal,
                            access_token: data.access_token,
                            business_id: data.business_id || ''
                        }).always(function() {
                            if (!data.business_id) {
                                postAjax({
                                    action: 'wonder_payments_sdk_get_businesses',
                                    security: nonces.modal
                                }).done(function(businessResponse) {
                                    if (businessResponse && businessResponse.success && businessResponse.data && businessResponse.data.data && businessResponse.data.data.length) {
                                        var firstBusiness = businessResponse.data.data[0];
                                        var businessId = firstBusiness.business_id || firstBusiness.id;
                                        if (businessId) {
                                            localStorage.setItem('wonder_business_id', businessId);
                                        }
                                    }
                                });
                            }
                        });

                        clearPolling();
                        showScanSuccess();

                        setTimeout(function() {
                            activateTab('business');
                        }, 1500);
                        return;
                    }

                    if (data.is_expired || data.is_cancel) {
                        clearPolling();
                    }
                });
            }, 2000);
        }

        $container
            .off('.wonderPaymentsModal')
            .on('click.wonderPaymentsModal', '#refresh-qr-btn', function(event) {
                event.preventDefault();
                generateQRCode();
            })
            .on('click.wonderPaymentsModal', '#logout-btn', function(event) {
                event.preventDefault();
                clearSessionStorage();
                setMenuLock(false);
                activateTab('scan');
            })
            .on('click.wonderPaymentsModal', '#recreate-btn', function(event) {
                event.preventDefault();
                clearSessionStorage();
                setMenuLock(false);

                postAjax({
                    action: 'wonder_payments_clear_all',
                    security: nonces.modal
                }).always(function() {
                    $container.find('.business-card').removeClass('selected connected');
                    activateTab('scan');
                });
            })
            .on('click.wonderPaymentsModal', '#create-app-id-btn', function(event) {
                event.preventDefault();
                var businessId = localStorage.getItem('wonder_selected_business_id');
                var businessName = localStorage.getItem('wonder_selected_business_name');

                if (!businessId) {
                    activateTab('business');
                    return;
                }

                generateAppIdOnly(businessId, businessName, function() {
                    setTimeout(function() {
                        activateTab('settings');
                    }, 300);
                });
            })
            .on('click.wonderPaymentsModal', '.menu-item', function(event) {
                event.preventDefault();
                activateTab($(this).data('tab'));
            })
            .on('click.wonderPaymentsModal', '.choose-btn', function(event) {
                event.preventDefault();
                saveSelectedBusiness($(this).data('business-id'), $(this).data('business-name'));
            })
            .on('click.wonderPaymentsModal', '#save-settings-btn', function(event) {
                event.preventDefault();
                saveSettings(false);
            })
            .on('click.wonderPaymentsModal', '#settings-sandbox', function(event) {
                event.preventDefault();
                var nextValue = $(this).attr('data-enabled') === 'true' ? 'false' : 'true';
                $(this).attr('data-enabled', nextValue);
            });

        window.addEventListener('message', function(event) {
            if (event.data && event.data.action === 'stopPolling') {
                clearPolling();
            }
        });

        $container.find('.content-panel').removeClass('active');
        $container.find('.menu-item').removeClass('active');
        setMenuLock(false);

        var accessToken = localStorage.getItem('wonder_access_token');
        var businessId = localStorage.getItem('wonder_business_id');
        var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');

        if (accessToken && businessId) {
            $container.find('#logout-section').show();
            $container.find('.qr-code-section').hide();
            $container.find('.qr-loading').html('<div class="scan-success">OK ' + escapeHtml(strings.scanSuccess) + '</div>').show();

            if (selectedBusinessId && selectedBusinessId !== 'null' && selectedBusinessId !== 'undefined') {
                activateTab('activation');
            } else {
                activateTab('business');
            }
        } else {
            activateTab('scan');
        }
    }

    $(function() {
        initGatewaySettings();
        initGatewayListPage();
        initOrderSync();
        initializeModalContent();
    });

    window.wonderPaymentsAdminInitModal = initializeModalContent;
})(jQuery);
