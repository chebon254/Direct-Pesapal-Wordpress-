jQuery(document).ready(function($) {
    
    // Handle payment status check
    window.checkPaymentStatus = function(orderTrackingId, donationId) {
        if (!confirm('Check payment status for this donation?')) {
            return;
        }
        
        const button = event.target;
        const originalText = button.textContent;
        button.textContent = 'Checking...';
        button.disabled = true;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'check_payment_status',
                order_tracking_id: orderTrackingId,
                donation_id: donationId,
                nonce: $('#pesapal_admin_nonce').val() || ''
            },
            success: function(response) {
                if (response.success) {
                    alert('Status updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    button.textContent = originalText;
                    button.disabled = false;
                }
            },
            error: function(xhr, status, error) {
                alert('Network error: ' + error);
                button.textContent = originalText;
                button.disabled = false;
            }
        });
    };
    
    // Enhanced filter function
    window.filterDonations = function() {
        const status = $('#status_filter').val();
        const url = new URL(window.location);
        
        if (status) {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        
        url.searchParams.delete('paged'); // Reset pagination
        window.location = url;
    };
    
    // Add keyboard support for filter
    $('#status_filter').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            filterDonations();
        }
    });
    
    // Auto-refresh functionality
    let autoRefreshInterval;
    
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(function() {
            // Only refresh if we're on the donations page and no modals are open
            if (window.location.href.includes('pesapal-donations') && !$('.modal:visible').length) {
                location.reload();
            }
        }, 60000); // Refresh every minute
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
    
    // Add auto-refresh toggle
    if ($('#pesapal-donations-page').length) {
        const refreshToggle = $(`
            <div class="auto-refresh-toggle" style="margin: 10px 0;">
                <label>
                    <input type="checkbox" id="auto-refresh" checked> 
                    Auto-refresh every minute
                </label>
            </div>
        `);
        
        $('.wrap h1').after(refreshToggle);
        
        $('#auto-refresh').on('change', function() {
            if ($(this).is(':checked')) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        // Start auto-refresh by default
        startAutoRefresh();
    }
    
    // Export functionality
    function exportDonations() {
        const status = $('#status_filter').val();
        const exportUrl = new URL(window.location);
        exportUrl.searchParams.set('action', 'export_donations');
        if (status) {
            exportUrl.searchParams.set('status', status);
        }
        
        // Create a temporary link and click it
        const link = document.createElement('a');
        link.href = exportUrl.toString();
        link.download = 'donations_export.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // Add export button
    if ($('.tablenav.top').length) {
        const exportBtn = $('<button type="button" class="button">Export CSV</button>');
        exportBtn.on('click', exportDonations);
        $('.tablenav.top .alignleft.actions').append(exportBtn);
    }
    
    // Enhanced table features
    function enhanceTable() {
        // Add sorting capability
        $('table.wp-list-table th').each(function() {
            const $th = $(this);
            const text = $th.text().trim();
            
            if (['Amount', 'Date', 'Status'].includes(text)) {
                $th.css('cursor', 'pointer')
                   .attr('title', 'Click to sort by ' + text)
                   .on('click', function() {
                       sortTable($th.index(), text);
                   });
            }
        });
        
        // Add row highlighting on hover
        $('table.wp-list-table tbody tr').hover(
            function() { $(this).addClass('highlight'); },
            function() { $(this).removeClass('highlight'); }
        );
    }
    
    function sortTable(columnIndex, columnType) {
        const table = $('table.wp-list-table tbody');
        const rows = table.find('tr').toArray();
        
        rows.sort(function(a, b) {
            const aVal = $(a).find('td').eq(columnIndex).text().trim();
            const bVal = $(b).find('td').eq(columnIndex).text().trim();
            
            if (columnType === 'Amount') {
                const aNum = parseFloat(aVal.replace(/[^0-9.-]+/g, ''));
                const bNum = parseFloat(bVal.replace(/[^0-9.-]+/g, ''));
                return aNum - bNum;
            } else if (columnType === 'Date') {
                return new Date(aVal) - new Date(bVal);
            } else {
                return aVal.localeCompare(bVal);
            }
        });
        
        // Toggle sort direction
        const isAsc = table.data('sort-' + columnIndex) !== 'asc';
        if (!isAsc) {
            rows.reverse();
        }
        table.data('sort-' + columnIndex, isAsc ? 'asc' : 'desc');
        
        table.empty().append(rows);
    }
    
    // Initialize enhancements
    enhanceTable();
    
    // Add custom styles
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .highlight { background-color: #f0f8ff !important; }
            .auto-refresh-toggle { 
                font-size: 12px; 
                color: #666; 
            }
            .status-completed { 
                color: #46b450; 
                font-weight: bold; 
            }
            .status-pending { 
                color: #ffb900; 
                font-weight: bold; 
            }
            .status-failed { 
                color: #dc3232; 
                font-weight: bold; 
            }
            .status-reversed { 
                color: #7f54b3; 
                font-weight: bold; 
            }
            table.wp-list-table th[title] {
                position: relative;
            }
            table.wp-list-table th[title]:hover::after {
                content: "↕";
                position: absolute;
                right: 5px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 10px;
            }
        `)
        .appendTo('head');
    
    // Settings page validation
    if ($('form').has('input[name="consumer_key"]').length) {
        $('form').on('submit', function(e) {
            const consumerKey = $('input[name="consumer_key"]').val().trim();
            const consumerSecret = $('input[name="consumer_secret"]').val().trim();
            
            if (!consumerKey || !consumerSecret) {
                e.preventDefault();
                alert('Please enter both Consumer Key and Consumer Secret.');
                return false;
            }
            
            if (consumerKey.length < 10 || consumerSecret.length < 10) {
                e.preventDefault();
                alert('Consumer Key and Secret seem too short. Please verify your credentials.');
                return false;
            }
        });
    }
    
    // Add donation statistics dashboard widget
    function createStatsWidget() {
        if (!$('.notice.notice-info').length) return;
        
        // Enhance the existing stats display
        const statsNotice = $('.notice.notice-info');
        const currentStats = statsNotice.find('p').html();
        
        // Add more detailed stats
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_donation_stats',
                nonce: $('#pesapal_admin_nonce').val() || ''
            },
            success: function(response) {
                if (response.success && response.data) {
                    const stats = response.data;
                    const enhancedStats = `
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                            <div><strong>Total Donations:</strong> ${stats.total_donations}</div>
                            <div><strong>Total Amount:</strong> KES ${stats.total_amount}</div>
                            <div><strong>Completed:</strong> ${stats.completed}</div>
                            <div><strong>Pending:</strong> ${stats.pending}</div>
                            <div><strong>Failed:</strong> ${stats.failed}</div>
                            <div><strong>This Month:</strong> KES ${stats.this_month}</div>
                        </div>
                    `;
                    
                    statsNotice.find('p').html(enhancedStats);
                }
            }
        });
    }
    
    createStatsWidget();
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        stopAutoRefresh();
    });
});