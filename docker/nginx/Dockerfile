FROM ubuntu:latest

# Install nginx
RUN apt-get update && apt-get install -y nginx

# Copy custom nginx configuration
COPY conf.d /etc/nginx/conf.d

# Expose port 80
EXPOSE 80

# Start nginx
CMD ["nginx", "-g", "daemon off;"]
