
jQuery(function($) {
	/* --------------------------------------------
	 Scroll To Top
	-------------------------------------------- */
    var totop = $('.scrollToTop');
	var win = $(window);   
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
        }, 800)
    });
	
	/* --------------------------------------------
	 		toggle class
	-------------------------------------------- */
	$( '.bottom .show-hide li' ).click(function() {
	  $(this).toggleClass( "open" );
	  $(this).next().toggle('500');
	  
	});

});