preview:
	sam local start-api

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
