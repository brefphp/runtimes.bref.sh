preview:
	php -S 127.0.0.1:8000 index.php

deploy:
	sam package \
        --output-template-file .stack.yaml \
        --s3-bucket bref-runtime-versions
	sam deploy \
        --template-file .stack.yaml \
        --capabilities CAPABILITY_IAM \
        --stack-name bref-runtime-versions
