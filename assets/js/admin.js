jQuery(document).ready(function($) {
    var modal = $('#log-details-modal');
    
    // Open modal and load log details
    $('.view-log-details').on('click', function() {
        var logId = $(this).data('log-id');
        
        // Show modal
        modal.show();
        $('.log-detail-loading').show();
        $('.log-detail-content').hide();
        
        // Make AJAX request
        $.ajax({
            url: commerceControlSuiteAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'commerce_control_suite_get_log_details',
                log_id: logId,
                nonce: commerceControlSuiteAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var log = response.data;
                    
                    // Populate modal
                    $('#log-detail-id').text(log.id);
                    $('#log-detail-ip').text(log.ip_address);
                    $('#log-detail-status').html('<span class="status-' + log.status + '">' + log.status + '</span>');
                    $('#log-detail-created').text(log.created_at);
                    $('#log-detail-processed').text(log.processed_at || 'N/A');
                    
                    // Format JSON data
                    $('#log-detail-body').text(log.request_body || 'No data');
                    
                    try {
                        var params = JSON.parse(log.request_params);
                        $('#log-detail-params').text(JSON.stringify(params, null, 2));
                    } catch(e) {
                        $('#log-detail-params').text(log.request_params || 'No data');
                    }
                    
                    try {
                        var headers = JSON.parse(log.request_headers);
                        $('#log-detail-headers').text(JSON.stringify(headers, null, 2));
                    } catch(e) {
                        $('#log-detail-headers').text(log.request_headers || 'No data');
                    }
                    
                    try {
                        var responseData = JSON.parse(log.response_data);
                        $('#log-detail-response').text(JSON.stringify(responseData, null, 2));
                    } catch(e) {
                        $('#log-detail-response').text(log.response_data || 'No data');
                    }
                    
                    $('.log-detail-loading').hide();
                    $('.log-detail-content').show();
                } else {
                    alert('Error: ' + response.data);
                    modal.hide();
                }
            },
            error: function() {
                alert('Failed to load log details');
                modal.hide();
            }
        });
    });
    
    // Close modal
    $('.log-modal-close, .log-modal-overlay').on('click', function() {
        modal.hide();
    });
    
    // Prevent modal content click from closing
    $('.log-modal-content').on('click', function(e) {
        e.stopPropagation();
    });

    // Currency rates repeater logic
    let rowIndex = $('#currency-rates-body tr').length;

    $('#add-currency-rate').on('click', function() {
        let optionName = $(this).data('option-name');
        let newRow = `
            <tr class="currency-rate-row">
                <td><input type="text" name="${optionName}[currencies][${rowIndex}][code]" class="regular-text" /></td>
                <td><input type="text" name="${optionName}[currencies][${rowIndex}][symbol]" class="regular-text" /></td>
                <td><input type="number" step="0.0001" name="${optionName}[currencies][${rowIndex}][rate]" class="regular-text" /></td>
                <td><button type="button" class="button remove-currency-rate">Remove</button></td>
            </tr>
        `;
        $('#currency-rates-body').append(newRow);
        rowIndex++;
    });

    $('#currency-rates-table').on('click', '.remove-currency-rate', function() {
        $(this).closest('tr').remove();
    });
});