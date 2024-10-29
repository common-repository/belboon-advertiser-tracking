jQuery(function () {
    'use strict';

    jQuery('.js-belboon-radio-trackingdomain-type').change(function (){
       var $this = jQuery(this),
           thisVal = parseInt($this.val()),
           $trackingDomainHint = jQuery('.js-belboon-tracking-domain-hint');

       if(thisVal === 0) {
           $trackingDomainHint.hide();
       } else {
           $trackingDomainHint.show();
       }
    });
});
