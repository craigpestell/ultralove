(function ($) {
    'use strict';

    $(function () {
        var $choice = $('#ultralove_dropship');
        if (!$choice.length) {
            return;
        }

        var $different = $('#ship-to-different-address-checkbox');
        var $email = $('#billing_email');
        var originalEmail = $email.val();
        var originalReadonly = $email.prop('readonly');
        var originalDifferent = $different.prop('checked');
        var originalShipping = {};
        $('.shipping_address :input[name^="shipping_"]').each(function () {
            originalShipping[this.name] = $choice.prop('checked')
                ? (ultraloveDropshipping.savedShipping[this.name.replace('shipping_', '')] || '')
                : $(this).val();
        });

        function applyChoice(clearRecipient) {
            var enabled = $choice.prop('checked');
            $('#ultralove-dropship-help').toggle(enabled);
            $('#ship-to-different-address').toggle(!enabled);
            $email.prop('readonly', enabled || originalReadonly);
            if (enabled) {
                $email.val(ultraloveDropshipping.retailerEmail);
                if (clearRecipient) {
                    $('.shipping_address :input[name^="shipping_"]').each(function () {
                        if (this.name !== 'shipping_country') {
                            $(this).val('').trigger('input').trigger('change.select2');
                        }
                    });
                }
                if (!$different.prop('checked')) {
                    $different.prop('checked', true).trigger('change');
                }
            } else {
                $email.val(originalEmail);
                $('.shipping_address :input[name^="shipping_"]').each(function () {
                    $(this).val(originalShipping[this.name]).trigger('change');
                });
                $different.prop('checked', originalDifferent).trigger('change');
            }
        }

        $choice.on('change', function () {
            applyChoice($choice.prop('checked'));
            $(document.body).trigger('update_checkout');
        });
        $different.on('change.ultraloveDropship', function () {
            if ($choice.prop('checked') && !$different.prop('checked')) {
                $different.prop('checked', true).trigger('change');
            }
        });
        if ($choice.prop('checked')) {
            applyChoice(false);
        } else {
            $('#ultralove-dropship-help').hide();
        }
    });
})(jQuery);
