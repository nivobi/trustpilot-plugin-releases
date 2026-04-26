import { useState } from '@wordpress/element';

export default function KeywordInput( { value, onChange, placeholder } ) {
	const tags = value
		? value
				.split( ',' )
				.map( ( t ) => t.trim() )
				.filter( Boolean )
		: [];

	const [ input, setInput ] = useState( '' );

	const addTag = ( raw ) => {
		const tag = raw.trim();
		if ( ! tag || tags.includes( tag ) ) {
			setInput( '' );
			return;
		}
		onChange( [ ...tags, tag ].join( ', ' ) );
		setInput( '' );
	};

	const removeTag = ( idx ) => {
		onChange(
			tags
				.filter( ( _, i ) => i !== idx )
				.join( ', ' )
		);
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ',' ) {
			e.preventDefault();
			addTag( input );
		} else if ( e.key === 'Backspace' && ! input && tags.length > 0 ) {
			removeTag( tags.length - 1 );
		}
	};

	return (
		<div
			className="tp-keyword-input"
			onClick={ ( e ) => e.currentTarget.querySelector( '.tp-keyword-field' )?.focus() }
		>
			{ tags.map( ( tag, i ) => (
				<span key={ i } className="tp-keyword-chip">
					{ tag }
					<button
						type="button"
						className="tp-chip-remove"
						onClick={ ( e ) => {
							e.stopPropagation();
							removeTag( i );
						} }
						aria-label={ `Remove ${ tag }` }
					>
						×
					</button>
				</span>
			) ) }
			<input
				type="text"
				className="tp-keyword-field"
				value={ input }
				onChange={ ( e ) => setInput( e.target.value ) }
				onKeyDown={ handleKeyDown }
				onBlur={ () => input.trim() && addTag( input ) }
				placeholder={
					tags.length === 0
						? ( placeholder || 'Type keyword, press Enter or , to add…' )
						: ''
				}
			/>
		</div>
	);
}
