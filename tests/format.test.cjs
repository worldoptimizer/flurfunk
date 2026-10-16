// Formatter checks: node tests/format.test.cjs
global.window = { flurfunkConfig: {} }; global.document = { addEventListener() {} };
require( require( "path" ).join( __dirname, "..", "assets", "app.js" ) );
const f = window.Flurfunk.format;
const cases = [
	[ 'Das ist *fett* und _kursiv_ und ~weg~.', 'Das ist <strong>fett</strong> und <em>kursiv</em> und <s>weg</s>.' ],
	[ 'Link https://ex.org/a_b_c*x* bleibt', 'Link <a href="https://ex.org/a_b_c*x*" target="_blank" rel="noopener noreferrer">https://ex.org/a_b_c*x*</a> bleibt' ],
	[ '`*nicht fett*` und `<b>`', '<code>*nicht fett*</code> und <code>&lt;b&gt;</code>' ],
	[ 'Liste:\n- eins\n- *zwei*\nEnde', 'Liste:<br><ul><li>eins</li><li><strong>zwei</strong></li></ul>Ende' ],
	[ '1. a\n2. b', '<ol><li>a</li><li>b</li></ol>' ],
	[ '> zitiert\n> weiter', '<blockquote>zitiert<br>weiter</blockquote>' ],
	[ '<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;' ],
	[ 'a*b*c und 3*4*5', 'a*b*c und 3*4*5' ],
	[ 'snake_case_name bleibt', 'snake_case_name bleibt' ],
	[ '```\n<i>code</i>\n```', '<pre class="ff-code">&lt;i&gt;code&lt;/i&gt;</pre>' ],
	[ 'Zeile1\nZeile2', 'Zeile1<br>Zeile2' ],
	[ '"><img src=x onerror=1> *x*', '&quot;&gt;&lt;img src=x onerror=1&gt; <strong>x</strong>' ],
];
let fail = 0;
for ( const [ i, o ] of cases ) { const r = f( i ); if ( r !== o ) { fail++; console.log( 'FAIL', JSON.stringify( i ), '\n  got ', r, '\n  want', o ); } }
console.log( fail ? fail + ' failed' : 'all ' + cases.length + ' ok' );
