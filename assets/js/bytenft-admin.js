jQuery(document).ready(function ($) {
     // Sanitize the PAYMENT_CODE parameter
  const PAYMENT_CODE = typeof params.PAYMENT_CODE === 'string' ? $.trim(params.PAYMENT_CODE) : '';

  function toggleSandboxFields() {
      if (PAYMENT_CODE) {
          const sandboxChecked = $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').is(':checked');
          const sandboxSelector = '.' + $.escapeSelector(PAYMENT_CODE) + '-sandbox-keys';
          const productionSelector = '.' + $.escapeSelector(PAYMENT_CODE) + '-production-keys';

          $(sandboxSelector).closest('tr').toggle(sandboxChecked);
          $(productionSelector).closest('tr').toggle(!sandboxChecked);
      }
  }

  toggleSandboxFields();

  $('#woocommerce_' + $.escapeSelector(PAYMENT_CODE) + '_sandbox').change(toggleSandboxFields);

    function updateAccountIndices() {
        $(".bytenft-account").each(function (index) {
            $(this).attr("data-index", index);
            $(this).find("input, select").each(function () {
                let name = $(this).attr("name");
                if (name) {
                    name = name.replace(/\[.*?\]/, "[" + index + "]");
                    $(this).attr("name", name);
                }
            });
        });
    }

    $(".account-info").hide();

    $(document).on("click", ".account-toggle-btn", function () {
        let accountInfo = $(this).closest(".bytenft-account").find(".account-info");
        accountInfo.slideToggle();
        $(this).toggleClass("rotated");
    });

    $(document).on("input", ".account-title", function () {
        let newTitle = $(this).val().trim() || "Untitled Account";
        $(this).closest(".bytenft-account").find(".account-name-display").text(newTitle);
    });

    /*$(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".bytenft-account").find(".sandbox-key");
        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val("");
        }
    }); */

    $(document).on("click", ".delete-account-btn", function () {
        const $accounts = $(".bytenft-account");
    
        // Remove any previous error message
        $(".delete-account-error").remove();
    
        if ($accounts.length === 1) {
            // Append inline error message before the account block
            $accounts.first().before('<div class="delete-account-error" style="color: red; margin-bottom: 10px;">At least one account must be present.</div>');
            return;
        }
    
        let $account = $(this).closest(".bytenft-account");
    
        // Remove account
        $account.find("input").each(function () {
            $(this).attr("name", ""); // Prevent form submission
        });
        $account.remove();
        updateAccountIndices();
    });
    

    $(document).on("click", ".bytenft-add-account", function () {
        let newAccountHtml = `
        <div class="bytenft-account">
            <div class="title-blog">
                <h4>
                    <span class="account-name-display">Untitled Account</span>
                    &nbsp;<i class="fa fa-caret-down account-toggle-btn" aria-hidden="true"></i>
                </h4>
                <div class="action-button">
                    <button type="button" class="delete-account-btn"><i class="fa fa-trash" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="account-info" style="display: none;">
				<div class="add-blog title-priority">
	                <div class="account-input account-name">
	                    <label>Account Name</label>
	                    <input type="text" class="account-title" name="accounts[][title]" placeholder="Account Title">
	                </div>
					<div class="account-input priority-name">
                        <label>Priority</label>
                        <input type="number" class="account-priority" name="accounts[][priority]" placeholder="Priority" min="1">
                    </div>
				</div>
                <div class="add-blog">
                    <div class="account-input">
                        <label>Live Keys</label>
                        <input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="Public Key">
                    </div>
                    <div class="account-input">
                        <input type="text" class="live-secret-key" name="accounts[][live_secret_key]" placeholder="Secret Key">
                    </div>
                </div>

                <div class="account-checkbox">
                    <input type="checkbox" class="sandbox-checkbox" name="accounts[][has_sandbox]">
                    Do you have the sandbox keys?
                </div>

                <div class="sandbox-key" style="display: none;">
                    <div class="add-blog">
                        <div class="account-input">
                            <label>Sandbox Keys</label>
                        <input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="Public Key">
                        </div>
                        <div class="account-input">
                            <input type="text" class="sandbox-secret-key" name="accounts[][sandbox_secret_key]" placeholder="Secret Key">
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        $(".bytenft-accounts-container .empty-account").remove();
        $(".bytenft-add-account").closest(".add-account-btn").before(newAccountHtml);
        updateAccountIndices();
    });

    function showErrorMessage(inputField, message) {
        // Function to show the error message next to the input field
        inputField.addClass("error");
        let errorMessage = $("<div>").addClass("error-message").text(message);
        inputField.after(errorMessage);
    }
    
    function clearErrorMessages() {
        // Function to clear all previous error messages
        $(".error-message").remove();
        $(".error").removeClass("error");
    }
    $(document).on("submit", "form", function (event) {
        clearErrorMessages(); // Clear previous errors
    
        let allKeys = new Set(); // Global key uniqueness check
        let prioritySet = new Set(); // For unique priority
        let titleSet = new Set();
        let hasErrors = false;
    
        // Helper for uniqueness validation
        function validateKeyUniqueness(inputField, keyValue, label) {
            if (allKeys.has(keyValue)) {
                showErrorMessage(inputField, `${label} must be unique across all accounts and key types.`);
                hasErrors = true;
            } else {
                allKeys.add(keyValue);
            }
        }
    
        $(".bytenft-account").each(function () {
            let livePublicKey = $(this).find(".live-public-key");
            let liveSecretKey = $(this).find(".live-secret-key");
            let sandboxPublicKey = $(this).find(".sandbox-public-key");
            let sandboxSecretKey = $(this).find(".sandbox-secret-key");
            let sandboxCheckbox = $(this).find(".sandbox-checkbox");
            let title = $(this).find(".account-title");
            let priority = $(this).find(".account-priority");
    
            let livePublicKeyVal = livePublicKey.val().trim();
            let liveSecretKeyVal = liveSecretKey.val().trim();
            let sandboxPublicKeyVal = sandboxPublicKey.val().trim();
            let sandboxSecretKeyVal = sandboxSecretKey.val().trim();
            let titleVal = title.val().trim();
            let priorityVal = priority.val().trim();
    
            // Title required
            if (!titleVal) {
                showErrorMessage(title, "Title is required.");
                hasErrors = true;
            } else if (titleSet.has(titleVal)) {
                showErrorMessage(title, "Title must be unique.");
                hasErrors = true;
            } else {
                titleSet.add(titleVal);
            }
        
    
            // Priority required & unique
            if (!priorityVal) {
                showErrorMessage(priority, "Priority is required.");
                hasErrors = true;
            } else if (prioritySet.has(priorityVal)) {
                showErrorMessage(priority, "Priority must be unique.");
                hasErrors = true;
            } else {
                prioritySet.add(priorityVal);
            }
    
            // Live keys required
            if (!livePublicKeyVal) {
                showErrorMessage(livePublicKey, "Live Public Key is required.");
                hasErrors = true;
            }
            if (!liveSecretKeyVal) {
                showErrorMessage(liveSecretKey, "Live Secret Key is required.");
                hasErrors = true;
            }
    
            // Validate all key uniqueness globally
            if (livePublicKeyVal) {
                validateKeyUniqueness(livePublicKey, livePublicKeyVal, "Live Public Key");
            }
            if (liveSecretKeyVal) {
                validateKeyUniqueness(liveSecretKey, liveSecretKeyVal, "Live Secret Key");
            }
            if (sandboxPublicKeyVal) {
                validateKeyUniqueness(sandboxPublicKey, sandboxPublicKeyVal, "Sandbox Public Key");
            }
            if (sandboxSecretKeyVal) {
                validateKeyUniqueness(sandboxSecretKey, sandboxSecretKeyVal, "Sandbox Secret Key");
            }
    
            // Same-account live key mismatch
            if (livePublicKeyVal && liveSecretKeyVal && livePublicKeyVal === liveSecretKeyVal) {
                showErrorMessage(liveSecretKey, "Live Secret Key must be different from Live Public Key.");
                hasErrors = true;
            }
    
            // Same-account sandbox key mismatch
            if (sandboxPublicKeyVal && sandboxSecretKeyVal && sandboxPublicKeyVal === sandboxSecretKeyVal) {
                showErrorMessage(sandboxSecretKey, "Sandbox Public Key and Sandbox Secret Key must be different.");
                hasErrors = true;
            }
    
            // Live vs sandbox mismatch check
            if (livePublicKeyVal && sandboxPublicKeyVal && livePublicKeyVal === sandboxPublicKeyVal) {
                showErrorMessage(sandboxPublicKey, "Live Public Key and Sandbox Public Key must be different.");
                hasErrors = true;
            }
            if (liveSecretKeyVal && sandboxSecretKeyVal && liveSecretKeyVal === sandboxSecretKeyVal) {
                showErrorMessage(sandboxSecretKey, "Live Secret Key and Sandbox Secret Key must be different.");
                hasErrors = true;
            }
    
            // Sandbox fields required if checkbox checked
            if (sandboxCheckbox.is(":checked")) {
                if (!sandboxPublicKeyVal) {
                    showErrorMessage(sandboxPublicKey, "Sandbox Public Key is required.");
                    hasErrors = true;
                }
                if (!sandboxSecretKeyVal) {
                    showErrorMessage(sandboxSecretKey, "Sandbox Secret Key is required.");
                    hasErrors = true;
                }
            }
        });
    
        if (hasErrors) {
            console.log("Form blocked due to validation errors.");
            event.preventDefault();
            $(this).find('[type="submit"]').removeClass('is-busy');
        } else {
            console.log("Form passed validation.");
        }
    });
    
  
    
    

    $(document).on("change", ".sandbox-checkbox", function () {
        let sandboxContainer = $(this).closest(".bytenft-account").find(".sandbox-key");
        if ($(this).is(":checked")) {
            sandboxContainer.show();
        } else {
            sandboxContainer.hide();
            sandboxContainer.find("input").val("").next(".error-message").remove(); // Clear errors if unchecked
        }
    });
    



    $('#bytenft-sync-accounts').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $status = $('#bytenft-sync-status');
        var originalButtonText = $button.text();
        
        // Set loading state
        $button.prop('disabled', true);
        $button.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Syncing...');
        $status.removeClass('error success').text('Syncing accounts...').show();
        
        $.ajax({
            url: bytenft_ajax_object.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'bytenft_manual_sync',
                nonce: bytenft_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.addClass('success').text(response.data.message || 'Sync completed successfully!');
                    
                    // Refresh the page after 2 seconds to show updated statuses
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $status.addClass('error').text(response.data.message || 'Sync failed. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'AJAX Error: ';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += xhr.responseJSON.data.message;
                } else {
                    errorMessage += error;
                }
                $status.addClass('error').text(errorMessage);
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.text(originalButtonText);
            }
        });
    });

    // Function to update all account statuses
    function updateAccountStatuses() {
        var sandboxEnabled = $('#woocommerce_bytenft_sandbox').is(':checked');

        $('.bytenft-account').each(function() {
            var $account = $(this);
            var liveStatus = $account.find('input[name$="[live_status]"]').val();
            var sandboxStatus = $account.find('input[name$="[sandbox_status]"]').val();
            if (!sandboxStatus) {
                sandboxStatus = 'unknown';
            }
            var $statusLabel = $account.find('.account-status-label');

            if (sandboxEnabled) {
                // Update class and text for sandbox mode
                $statusLabel
                    .removeClass('live-status invalid active inactive')
                    .addClass('sandbox-status ' + sandboxStatus.toLowerCase())
                    .text('Sandbox Account Status: ' + capitalizeFirstLetter(sandboxStatus));
            } else {
                // Update class and text for live mode
                $statusLabel
                    .removeClass('sandbox-status invalid active inactive')
                    .addClass('live-status ' + liveStatus.toLowerCase())
                    .text('Live Account Status: ' + capitalizeFirstLetter(liveStatus));
            }
        });
    }

    // When checkbox is changed, update statuses
    $('#woocommerce_bytenft_sandbox').on('change', function() {
        updateAccountStatuses();
    });

    // Optional: Update once on page load also (in case something is missed)
   // updateAccountStatuses();


// Function to capitalize the first letter
function capitalizeFirstLetter(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}


});


