/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/*eslint max-depth: 0*/
define('js/theme', [
    'jquery',
    'domReady!',
    'https://kit.fontawesome.com/fc569eac4d.js'
], function ($) {
    'use strict';
    console.log("biennnn2!")
    $("input[name$='cars']").click(function() {
        debugger
        var paymentMethod = $(this).val();
        if(paymentMethod != "creditCard"){
            $(".menu-select").hide();
            if(paymentMethod != "pse"){
                $("#pseSelector").hide();
                $("#cashSelector").show();
                $("#expiration-cash-date").show();
                $("#typePersonSelector").show();
                $("#typePerson").show();
            }else{
                $("#pseSelector").show();
                $("#cashSelector").hide();
                $("#expiration-cash-date").hide();
                $("#typePersonSelector").show();
                $("#typePerson").show();
            }
        }else{
            $(".menu-select").show();
            $("#pseSelector").hide();
            $("#cashSelector").hide();
            $("#expiration-cash-date").hide();
            $("#typePersonSelector").hide();
            $("#typePerson").hide();
        }
    });
});
