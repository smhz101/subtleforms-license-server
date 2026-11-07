<?php
/**
 * Front-end assets: one stylesheet and a tiny inline script.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the shared front-end CSS/JS.
 */
final class SFLS_Assets {

	/**
	 * Hook.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_handles' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Register the handles (safe to call more than once).
	 */
	public static function register_handles(): void {
		if ( ! wp_style_is( 'sfls-front', 'registered' ) ) {
			wp_register_style( 'sfls-front', SFLS_URL . 'assets/css/sfls-front.css', array(), SFLS_VERSION );
		}
		if ( ! wp_script_is( 'sfls-front', 'registered' ) ) {
			wp_register_script( 'sfls-front', false, array(), SFLS_VERSION, true );
			wp_add_inline_script( 'sfls-front', self::inline_js() );
		}
	}

	/**
	 * Enqueue on WooCommerce account pages.
	 */
	public function maybe_enqueue(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue the stylesheet and script.
	 */
	public static function enqueue(): void {
		self::register_handles();
		wp_enqueue_style( 'sfls-front' );
		wp_enqueue_script( 'sfls-front' );
	}

	/**
	 * Copy-to-clipboard and confirm helpers.
	 */
	private static function inline_js(): string {
		return <<<'JS'
(function(){
	var acc=function(){return document.querySelectorAll('details.sfls-license-acc');};
	acc().forEach(function(d){d.addEventListener('toggle',function(){
		if(!d.open){return;}
		acc().forEach(function(o){if(o!==d&&o.open){o.open=false;}});
	});});
	if(location.hash){var t=document.getElementById(location.hash.slice(1)),p=t&&t.closest('details.sfls-license-acc');if(p){p.open=true;}}
	document.addEventListener('click',function(e){
		var c=e.target.closest('[data-sfls-confirm]');
		if(c&&!window.confirm(c.getAttribute('data-sfls-confirm'))){e.preventDefault();return;}
		var b=e.target.closest('[data-sfls-copy]');
		if(!b){return;}
		e.preventDefault();
		var text=b.getAttribute('data-sfls-copy'),label=b.querySelector('.sfls-copy-label')||b,orig=label.textContent;
		var live=document.getElementById('sfls-live');
		if(!live){live=document.createElement('div');live.id='sfls-live';live.style.cssText='position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap';live.setAttribute('aria-live','polite');live.setAttribute('role','status');document.body.appendChild(live);}
		var done=function(){var msg=b.getAttribute('data-sfls-copied')||'Copied';label.textContent=msg;live.textContent='';setTimeout(function(){live.textContent=msg;},50);b.classList.add('is-copied');if(document.activeElement!==b){b.focus();}setTimeout(function(){label.textContent=orig;b.classList.remove('is-copied');live.textContent='';},1600);};
		if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done,function(){});return;}
		var t=document.createElement('textarea');t.value=text;t.setAttribute('readonly','');t.style.position='absolute';t.style.left='-9999px';document.body.appendChild(t);t.select();
		try{document.execCommand('copy');done();}catch(x){}
		document.body.removeChild(t);b.focus();
	});
})();
JS;
	}
}
