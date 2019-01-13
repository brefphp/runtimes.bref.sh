preview:
	php -S 127.0.0.1:8000 index.php

deploy:
	sam package \
        --output-template-file .stack.yaml \
        --region us-east-1 \
        --s3-bucket bref-runtime-versions-website
	sam deploy \
        --template-file .stack.yaml \
        --capabilities CAPABILITY_IAM \
        --region us-east-1 \
        --stack-name bref-runtime-versions-website
