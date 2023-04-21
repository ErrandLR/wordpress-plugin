jQuery(document).ready(function ($) {
  let errandlrGetshipment = function () {
    //get errandlr
    let image = $(".Errandlr-delivery-logo");
    //get parent
    let parent = image.parent().parent();
    //if the checkout form is valid
    if ($("form.checkout").length > 0) {
      //get the form data
      var data = {
        action: "errandlr_validate_checkout",
        nonce: errandlr_delivery.nonce,
        data: $("form.checkout").serialize()
      };
      //log
      $.ajax({
        type: "POST",
        url: errandlr_delivery.ajax_url,
        data,
        dataType: "json",
        beforeSend: function () {
          //block the checkout form
          $("form.checkout").block({
            message: null,
            overlayCSS: {
              background: "#fff",

              opacity: 0.6
            }
          });
        },
        success: function (response) {
          //clear session storage
          sessionStorage.removeItem("errandlr_shipment_info");
          //clear local storage
          localStorage.removeItem("errandlr_div_cost");
          //unblock the checkout form
          $("form.checkout").unblock();
          //check if response code is 200
          if (response.code == 200) {
            //get shipment_info
            let shipment_info = response.shipment_info;
            //save to session storage
            sessionStorage.setItem(
              "errandlr_shipment_info",
              JSON.stringify(shipment_info)
            );
            //economy_cost
            let economy_cost = shipment_info.economy_cost;
            //format economy_cost
            economy_cost = new Intl.NumberFormat("en-US", {
              style: "currency",
              currency: shipment_info.currency,
              currencyDisplay: "narrowSymbol",
              //remove decimal
              minimumFractionDigits: 0
            }).format(economy_cost);
            //premium_cost
            let premium_cost = shipment_info.premium_cost;
            //format premium_cost
            premium_cost = new Intl.NumberFormat("en-US", {
              style: "currency",
              currency: shipment_info.currency,
              currencyDisplay: "narrowSymbol",
              //remove decimal
              minimumFractionDigits: 0
            }).format(premium_cost);
            //currency
            let currency = shipment_info.currency;
            //content
            let content = `
              <div class="errandlr_container_div">
                  <p class="errandlr_premium_delivery" onclick="errandlrUpdatePrice(this, event)" data-premium="true">
                      Premium delivery 2-4 hrs ${premium_cost}
                  </p>
                  <p class="errandlr_economy_delivery" onclick="errandlrUpdatePrice(this, event)" data-premium="false">
                      Economy delivery 1-5 days ${economy_cost}
                  </p>
              </div>
            `;
            //check if parent has .errandlr_container_div
            if (parent.find(".errandlr_container_div").length > 0) {
              //replace .errandlr_container_div
              parent.find(".errandlr_container_div").replaceWith(content);
            } else {
              //append to li
              parent.append(content);
            }
            //local storage
            localStorage.setItem("errandlr_div_cost", content);
          }
        },
        error: function (response) {
          //unblock the checkout form
          $("form.checkout").unblock();
          //log the error
          console.log(response);
        }
      });
    }
  };

  //update content storage
  let updateContentStorage = function () {
    //get current url
    let current_url = window.location.href;
    //check if current url match cart
    if (current_url.match(/cart/g)) {
      //clear session storage
      sessionStorage.removeItem("errandlr_shipment_info");
      //clear local storage
      localStorage.removeItem("errandlr_div_cost");
      return;
    }
    //get errandlr
    let image = $(".Errandlr-delivery-logo");
    //get parent
    let parent = image.parent().parent();
    //check if local storage has errandlr_div_cost
    if (localStorage.getItem("errandlr_div_cost")) {
      //get content
      let content = localStorage.getItem("errandlr_div_cost");
      //check if parent has .errandlr_container_div
      if (parent.find(".errandlr_container_div").length > 0) {
        //replace .errandlr_container_div
        parent.find(".errandlr_container_div").replaceWith(content);
      } else {
        //append to li
        parent.append(content);
      }
    }
  };

  //set interval
  setInterval(updateContentStorage, 1000);

  //on focus out on any input field or select box in the checkout form 'checkout woocommerce-checkout'
  $("body").on(
    "focusout",
    "form.checkout #billing_address_1, form.checkout #billing_address_2, form.checkout #billing_city, form.checkout #billing_state, form.checkout #billing_postcode, form.checkout #billing_country, form.checkout #billing_phone, form.checkout #billing_email",
    errandlrGetshipment
  );

  //init
  errandlrGetshipment();
});

//errandlrUpdatePrice
let errandlrUpdatePrice = function (elem, e) {
  e.preventDefault();
  jQuery(document).ready(function ($) {
    //get errandlr shipping input
    let errandlrimage = $(".Errandlr-delivery-logo");
    //get parent element
    let errandlr_image_parent = errandlrimage.parent();
    //get previous element
    let errandlr_image_prev = errandlr_image_parent.prev();
    //check if errandlr_image_prev is not empty
    if (errandlr_image_prev.length) {
      //check if errandlr_image_prev is input type radio
      if (errandlr_image_prev.is("input[type='radio']")) {
        //check the input
        errandlr_image_prev.prop("checked", true);
      }
    }
    //shipping info
    let shipping_info_data = {};
    //check if session storage has errandlr_shipment_info
    if (sessionStorage.getItem("errandlr_shipment_info")) {
      //get shipment_info
      let shipment_info = JSON.parse(
        sessionStorage.getItem("errandlr_shipment_info")
      );
      //set
      shipping_info_data = shipment_info;
    }
    //get premium
    let premium = $(elem).data("premium");
    //save to session
    $.ajax({
      type: "POST",
      url: errandlr_delivery.ajax_url,
      data: {
        action: "errandlr_africa_save_shipping_info",
        nonce: errandlr_delivery.nonce,
        shipping_info: shipping_info_data,
        premium: premium
      },
      dataType: "json",
      beforeSend: function () {
        //block the checkout form
        $("form.checkout").block({
          message: null,
          overlayCSS: {
            background: "#fff",
            opacity: 0.6
          }
        });
      },
      success: function (response) {
        //unblock the checkout form
        $("form.checkout").unblock();
        //if response code 200
        if (response.code == 200) {
          //update woocommerce
          $(document.body).trigger("update_checkout");
        } else {
          //alert
          alert("Something went wrong: " + response.message);
        }
      },
      error: function (response) {
        //unblock the checkout form
        $("form.checkout").unblock();
        //log the error
        console.log(response);
      }
    });
  });
};
