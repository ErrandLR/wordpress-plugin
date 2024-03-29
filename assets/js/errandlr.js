jQuery(document).ready(function ($) {
  //trigger debounce event
  let errandlrDebounce = (element) => {
    var typingTimer; //timer identifier
    var doneTypingInterval = 1000; //time in ms (5 seconds)

    //on keyup, start the countdown
    $(element).keyup(function () {
      clearTimeout(typingTimer);
      if ($(element).val()) {
        typingTimer = setTimeout(errandlrGetshipment, doneTypingInterval);
      }
    });
  };

  //clear errand selected shipment
  let clearErrand = () => {
    //clear local storage errandlr_shipment_info
    localStorage.removeItem("errandlr_shipment_info");
    //check if element exist
    if ($(".errandlr_container_div").length) {
      //clear .errandlr_container_div
      $(".errandlr_container_div").remove();
    }
    //ajax
    $.ajax({
      type: "GET",
      url: errandlr_delivery.ajax_url,
      data: {
        action: "errandlr_clear_selected_shipment",
        nonce: errandlr_delivery.nonce
      },
      dataType: "json",
      success: function (response) {
        //check if response is 200
        if (response.code == 200) {
          //get errandlr
          let image = $(".Errandlr-delivery-logo");
          //get parent
          let parent = image.parent().parent();
          //find .woocommerce-Price-amount amount
          let amount = parent.find(".woocommerce-Price-amount.amount");
          //check if it exist
          if (amount.length > 0) {
            //update woocommerce
            $(document.body).trigger("update_checkout");
          }
        } else {
          console.log(response);
        }
      }
    });
  };

  clearErrand();

  let errandlrGetshipment = function () {
    //get errandlr
    let image = $(".Errandlr-delivery-logo");
    //get parent
    let parent = image.parent().parent();
    //get the parent form on checkout
    let formWoo = $("#customer_details").closest("form");
    //if the checkout form is valid
    if (formWoo.length > 0) {
      //get the form data
      var data = {
        action: "errandlr_validate_checkout",
        nonce: errandlr_delivery.nonce,
        data: formWoo.serialize()
      };
      clearErrand();
      //log
      $.ajax({
        type: "POST",
        url: errandlr_delivery.ajax_url,
        data,
        dataType: "json",
        beforeSend: function () {
          //block the checkout form
          parent.block({
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
          parent.unblock();
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
            //get label
            let label = parent.find("label");
            //get label text
            let label_text = label.text();
            //has premium
            let has_premium = false;
            //has economy
            let has_economy = false;
            //check if label text has 'Premium delivery'
            if (label_text.match(/Premium/g)) {
              //  has_premium
              has_premium = true;
            }
            //check if label text has 'Economy delivery'
            if (label_text.match(/Economy/g)) {
              //  has_economy
              has_economy = true;
            }
            //content
            let content = `
              <div class="errandlr_container_div">
                  <p class="errandlr_premium_delivery ${
                    has_premium ? "errandlr_active" : ""
                  }" onclick="errandlrUpdatePrice(this, event)" data-premium="true">
                      Premium delivery: <b>2-4 hrs ${premium_cost}</b>
                  </p>
                  <p class="errandlr_economy_delivery ${
                    has_economy ? "errandlr_active" : ""
                  }" onclick="errandlrUpdatePrice(this, event)" data-premium="false">
                      Economy delivery: <b>1-5 days ${economy_cost}</b>
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
          formWoo.unblock();
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
    //check if image is found
    if (!image.length) {
      return;
    }
    //check if woocommerce-info has test that match 'Errandlr Delivery'
    if (
      $(".woocommerce-info")
        .text()
        .match(/Errandlr Delivery/g)
    ) {
      //remove notice
      $(".woocommerce-info").parent().remove();
    }
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
        //get label
        let label = parent.find("label");
        //get label text
        let label_text = label.text();
        //check if label text has 'Premium delivery'
        if (label_text.match(/Premium/g)) {
          //remove errandlr_active
          $(
            ".errandlr_economy_delivery, .errandlr_premium_delivery"
          ).removeClass("errandlr_active");
          //add errandlr_active
          $(".errandlr_premium_delivery").addClass("errandlr_active");
        }
        //check if label text has 'Economy delivery'
        if (label_text.match(/Economy/g)) {
          //remove errandlr_active
          $(
            ".errandlr_economy_delivery, .errandlr_premium_delivery"
          ).removeClass("errandlr_active");
          //add errandlr_active
          $(".errandlr_economy_delivery").addClass("errandlr_active");
        }

        //check if label does not match any of the above
        if (!label_text.match(/Economy/g) && !label_text.match(/Premium/g)) {
          //remove errandlr_active
          $(
            ".errandlr_economy_delivery, .errandlr_premium_delivery"
          ).removeClass("errandlr_active");
        }
      } else {
        //append to li
        parent.append(content);
      }

      //check if parent has element .woocommerce-Price-amount
      if (!parent.find(".woocommerce-Price-amount").length) {
        //trigger click on the premium delivery
        $(".errandlr_premium_delivery").trigger("click");
      }
    }
  };

  //set interval
  setInterval(updateContentStorage, 1000);

  $("body").on(
    "change",
    "form #billing_state, form #billing_country",
    errandlrGetshipment
  );

  //errandlrDebounce
  errandlrDebounce("form #billing_address_1");
  errandlrDebounce("form #billing_address_2");
  errandlrDebounce("form #billing_city");

  //init
  errandlrGetshipment();

  //on update checkout
  $(document.body).on("updated_checkout", function () {
    //init
    // errandlrGetshipment();
  });
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
