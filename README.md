# cloudflare-ddns
Simple PHP script that takes standard Dyanmic DNS params and then updates with the Cloudfare API

# Instructions
1. Upload index.php to where you want to run the script.

2. Create a Cloudflare API token as per:
https://developers.cloudflare.com/fundamentals/api/get-started/create-token/

3. Create a A record for your desired subdomain with a TTL set to the frequency of update by your DDNS clients.

4. Setup your DDNS client as per:
