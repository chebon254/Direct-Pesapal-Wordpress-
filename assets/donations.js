jQuery(document).ready(function($) {
    // Handle donation form submission
    $('#donation-form').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const messageDiv = $('#donation-message');
        
        // Disable submit button and show loading
        submitBtn.prop('disabled', true).text('Processing...');
        messageDiv.hide();
        
        // Validate form
        const formData = {
            action: 'process_donation',
            nonce: pesapal_ajax.nonce,
            donor_name: $('#donor_name').val().trim(),
            donor_email: $('#donor_email').val().trim(),
            donor_phone: $('#donor_phone').val().trim(),
            donor_id_number: $('#donor_id_number').val().trim(),
            amount: parseFloat($('#amount').val())
        };
        
        // Client-side validation
        if (!formData.donor_name || !formData.donor_email || !formData.donor_phone || 
            !formData.donor_id_number || !formData.amount || formData.amount <= 0) {
            showMessage('Please fill in all required fields correctly.', 'error');
            submitBtn.prop('disabled', false).text('Donate Now');
            return;
        }
        
        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(formData.donor_email)) {
            showMessage('Please enter a valid email address.', 'error');
            submitBtn.prop('disabled', false).text('Donate Now');
            return;
        }
        
        // Validate phone number (Kenyan format)
        const phoneRegex = /^(\+254|254|0)?[7][0-9]{8}$/;
        if (!phoneRegex.test(formData.donor_phone.replace(/\s+/g, ''))) {
            showMessage('Please enter a valid Kenyan phone number (e.g., 0712345678).', 'error');
            submitBtn.prop('disabled', false).text('Donate Now');
            return;
        }
        
        // Submit to server
        $.ajax({
            url: pesapal_ajax.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showMessage('Redirecting to payment gateway...', 'success');
                    // Redirect to Pesapal payment page
                    window.location.href = response.data.redirect_url;
                } else {
                    showMessage('Error: ' + (response.data || 'Unknown error occurred'), 'error');
                    submitBtn.prop('disabled', false).text('Donate Now');
                }
            },
            error: function(xhr, status, error) {
                showMessage('Network error: Please check your connection and try again.', 'error');
                submitBtn.prop('disabled', false).text('Donate Now');
                console.error('AJAX Error:', error);
            }
        });
    });
    
    // Format phone number as user types
    $('#donor_phone').on('input', function() {
        let value = $(this).val().replace(/\D/g, ''); // Remove non-digits
        
        // Add leading zero if starts with 7
        if (value.length > 0 && value[0] === '7') {
            value = '0' + value;
        }
        
        // Format as 0XXX XXX XXX
        if (value.length > 4 && value.length <= 7) {
            value = value.slice(0, 4) + ' ' + value.slice(4);
        } else if (value.length > 7) {
            value = value.slice(0, 4) + ' ' + value.slice(4, 7) + ' ' + value.slice(7, 10);
        }
        
        $(this).val(value);
    });
    
    // Format amount input
    $('#amount').on('input', function() {
        let value = parseFloat($(this).val());
        if (isNaN(value) || value < 0) {
            $(this).val('');
        }
    });
    
    // Format ID number input (remove spaces and limit length)
    $('#donor_id_number').on('input', function() {
        let value = $(this).val().replace(/\s+/g, '').slice(0, 8); // Max 8 digits for Kenyan ID
        $(this).val(value);
    });
    
    // Capitalize name as user types
    $('#donor_name').on('input', function() {
        let value = $(this).val();
        // Capitalize first letter of each word
        value = value.replace(/\b\w/g, function(letter) {
            return letter.toUpperCase();
        });
        $(this).val(value);
    });
    
    function showMessage(message, type) {
        const messageDiv = $('#donation-message');
        messageDiv.removeClass('success error')
                  .addClass(type)
                  .text(message)
                  .show();
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                messageDiv.fadeOut();
            }, 5000);
        }
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: messageDiv.offset().top - 100
        }, 300);
    }
    
    // Handle URL parameters for donation status
    function checkDonationStatus() {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('donation_status');
        
        if (status === 'processing') {
            showMessage('Your donation is being processed. You will receive a confirmation shortly.', 'success');
        } else if (status === 'success') {
            showMessage('Thank you! Your donation has been successfully processed.', 'success');
        } else if (status === 'failed') {
            showMessage('Payment failed. Please try again or contact support.', 'error');
        }
    }
    
    // Check status on page load
    checkDonationStatus();
    
    // Add loading animation
    function addLoadingAnimation() {
        if (!$('#loading-style').length) {
            $('head').append(`
                <style id="loading-style">
                .pesapal-btn.loading {
                    position: relative;
                    color: transparent;
                }
                .pesapal-btn.loading::after {
                    content: "";
                    position: absolute;
                    width: 16px;
                    height: 16px;
                    top: 50%;
                    left: 50%;
                    margin-left: -8px;
                    margin-top: -8px;
                    border-radius: 50%;
                    border: 2px solid transparent;
                    border-top-color: #ffffff;
                    animation: spin 1s ease infinite;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                </style>
            `);
        }
    }
    
    addLoadingAnimation();
    
    // Update submit button with loading state
    $('#donation-form').on('submit', function() {
        $(this).find('button[type="submit"]').addClass('loading');
    });
});