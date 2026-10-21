#!/usr/bin/env node
import {Construct} from 'constructs';
import {App, CfnOutput, Duration, Stack, StackProps} from 'aws-cdk-lib';
import {PhpFunction} from '@bref.sh/constructs';
import {DomainName, LambdaRestApi} from 'aws-cdk-lib/aws-apigateway';
import {EndpointType} from 'aws-cdk-lib/aws-apigateway';
import {Certificate} from 'aws-cdk-lib/aws-certificatemanager';

class MyStack extends Stack {
    constructor(scope: Construct, id: string, props?: StackProps) {
        super(scope, id, props);

        const handler = new PhpFunction(this, 'Web', {
            functionName: 'bref-runtime-versions-v2',
            description: 'runtimes.bref.sh',
            handler: 'handler.php',
            phpVersion: '8.5',
            // API Gateway has a 29s timeout
            timeout: Duration.seconds(28),
        });

        const api = new LambdaRestApi(this, 'Api', {
            description: 'runtimes.bref.sh',
            restApiName: 'runtimes.bref.sh',
            handler,
            disableExecuteApiEndpoint: true,
        });
        // Custom domain
        new DomainName(this, 'Domain', {
            domainName: 'runtimes.bref.sh',
            endpointType: EndpointType.EDGE,
            mapping: api,
            certificate: Certificate.fromCertificateArn(this, 'Certificate', 'arn:aws:acm:us-east-1:416566615250:certificate/313a125e-1155-4aff-a40f-08c932a2926d'),
        });

        new CfnOutput(this, 'ApiUrl', {value: api.url});
    }
}

const app = new App();
new MyStack(app, 'bref-runtime-versions', {
    env: {
        region: 'us-east-1',
    },
    description: 'runtimes.bref.sh',
});
