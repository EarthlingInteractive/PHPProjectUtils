verify-php-syntax:
	: # Make sure all the PHP files are syntactically valid
	find lib -name '*.php' | xargs php
