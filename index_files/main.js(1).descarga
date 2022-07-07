/**

*

* -----------------------------------------------------------------------------

*

* Template : Grassy - Business & Corporate Wordpress Theme 

* Author : rs-theme

* Author URI : http://www.rstheme.com/

*

* -----------------------------------------------------------------------------

*

**/
(function($) {

    "use strict";   

  // Add "loaded" class when a section has been loaded

  $(window).scroll(function() { 

    var scrollTop = $(window).scrollTop();

    $("section").each(function() {

      var elementTop = $(this).offset().top - $('#rs-header').outerHeight();

      if(scrollTop >= elementTop) {

        $(this).addClass('loaded');

      }

    });

  });

  // Sticky Navbar Affix

  $('.menu-sticky').affix({

    offset: {

      top: $('.toolbar-area').outerHeight(),

    }

  });


	// video 
	
	if ($('.player').length) {
	
		$(".player").YTPlayer();
	
	}

    // image loaded portfolio init

    $('.grid').imagesLoaded(function() {

        $('.portfolio-filter').on('click', 'button', function() {

            var filterValue = $(this).attr('data-filter');

            $grid.isotope({

                filter: filterValue

            });

        });

        var $grid = $('.grid').isotope({

            itemSelector: '.grid-item',

            percentPosition: true,

            masonry: {

                columnWidth: '.grid-item',

            }

        });

    });        

        

    // portfolio Filter

    $('.portfolio-filter button').on('click', function(event) {

        $(this).siblings('.active').removeClass('active');

        $(this).addClass('active');

        event.preventDefault();

    });

	



    // magnificPopup init

    $('.image-popup').magnificPopup({

        type: 'image',

        callbacks: {

            beforeOpen: function() {

               this.st.image.markup = this.st.image.markup.replace('mfp-figure', 'mfp-figure animated zoomInDown');

            }

        },

        gallery: {

            enabled: true

        }

    });

    

    // Get a quote popup



    $('.popup-quote').magnificPopup({

        type: 'inline',

        preloader: false,

        focus: '#qname',

        removalDelay: 500, //delay removal by X to allow out-animation

        // When elemened is focused, some mobile browsers in some cases zoom in

        // It looks not nice, so we disable it:

        callbacks: {

            beforeOpen: function() {

                this.st.mainClass = this.st.el.attr('data-effect');

                if($(window).width() < 700) {

                    this.st.focus = false;

                } else {

                    this.st.focus = '#qname';

                }

            }

        }

    });


	 // team init

    $(".team-carousel").each(function() {
            var options = $(this).data('carousel-options');
            $(this).owlCarousel(options); 
    });


    // testimonial init   

    $('.testi-carousel').slick({

          centerMode: true,

          centerPadding: '0px',

          slidesToShow: 3,

          focusOnSelect: true,

          responsive: [

            {

              breakpoint: 768,

              settings: {

                arrows: false,

                centerMode: true,

                centerPadding: '0px',

                slidesToShow: 3

              }

            },

            {

              breakpoint: 480,

              settings: {

                arrows: false,

                centerMode: true,

                centerPadding: '0px',

                slidesToShow: 1

              }

            }

          ]

        });

		

    

    $(".testi-item  a.tab").on('click', function(e){

      e.preventDefault();

      slideIndex = $(this).index();

      $( '.testi-carousel' ).slickGoTo( parseInt(slideIndex) );

    });



  /*-------------------------------------

    Main Menu jQuery activation code

    -------------------------------------*/

   $(".menu li a")

        .on("click", function(e) {

            var link = $(this);



            var item = link.parent("li");

            

            if (item.hasClass("active-open")) {

                item.removeClass("active-open").children("a").removeClass("active-open");

            } else {

                item.addClass("active-open").children("a").addClass("active-open");

            }



            if (item.children("ul").length > 0) {

                var href = link.attr("href");

                link.attr("href", "#");

                setTimeout(function () { 

                    link.attr("href", href);

                }, 300);

                e.preventDefault();

            }

        })

        .each(function() {

            var link = $(this);

            if (link.get(0).href === location.href) {

                link.addClass("active-open").parents("li").addClass("active-open");

                return false;

            }

    });

	

	 //window Load Remove Class

    $(window).on( 'load', function() {

        $('.menu li').removeClass('active-open');

    })



    // Canvas Menu Js

    $(".sidenav #menu-canvas-menu .menu-item-has-children").prepend("<span class='togglearrow'><i class='fa fa-chevron-down'></i></span>");
    
    $(".sidenav").on("click", "#menu-canvas-menu .menu-item-has-children", function(){
        $('ul',this).slideToggle();
    });
    
    $( ".nav-link-container > a" ).on('click', function(event){

        $(".nav-link-container").toggleClass("nav-inactive-menu-link-container");

        $(".sidenav").toggleClass("nav-active-menu-container");
        $(".nav-container").toggleClass("flip");
        $(".off-canvas-overlay").toggleClass("open");

    });



    $(".nav-close-menu-li > a").on('click', function(event){

        $(".sidenav").toggleClass("nav-active-menu-container");

        $(".content").toggleClass("inactive-body");
        $(".nav-container").toggleClass("flip");
        $(".off-canvas-overlay").toggleClass("open");

      });

    $(".off-canvas-overlay").on('click', function(event){

        $(".sidenav").toggleClass("nav-active-menu-container");

        $(".content").toggleClass("inactive-body");
        $(".nav-container").toggleClass("flip");
        $(".off-canvas-overlay").toggleClass("open");

      });



    // blog init

    $(".blog-carousel").each(function() {
            var options = $(this).data('carousel-options');
            $(this).owlCarousel(options); 
    });


    // partner init

    $(".partner-carousel").each(function() {
            var options = $(this).data('carousel-options');
            $(this).owlCarousel(options); 
    });



    $('.sticky_search').on('click', function(event) {        

        $('.sticky_form').toggle('show');

    });

	

    //preloader

    $(window).on('load', function() {

        $(".preloader").delay(2000).fadeOut(500);

        $(".sk-cube-grid").on('click', function() {

        $(".preloader").fadeOut(500);

        })

    })

		

    // Counter Up  

    $('.rs-counter').counterUp({

        delay: 20,

        time: 1500

    });

    $(function(){ 
        $( "ul.children" ).addClass( "sub-menu" );
    });
	
    // scrollTop init

	var win=$(window);

    var totop = $('#scrollUp');    

    win.on('scroll', function() {

        if (win.scrollTop() > 150) {

            totop.fadeIn();

        } else {

            totop.fadeOut();

        }

    });

    totop.on('click', function() {

        $("html,body").animate({

            scrollTop: 0

        }, 500)

    });

  
})(jQuery);