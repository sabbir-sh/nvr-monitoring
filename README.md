# NVR Desk

একই local network-এ থাকা ১০০+ NVR-এর inventory ও ICMP ping health check-এর জন্য Laravel অ্যাপ।

## চালু করা

WAMP-এ MySQL চালু করে নিশ্চিত করুন `.env`-এ আপনার local MySQL database-এর নাম, username ও password দেওয়া আছে। উদাহরণ:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nvr_monitoring
DB_USERNAME=root
DB_PASSWORD=
```

MySQL-এ database আগে থেকে না থাকলে phpMyAdmin-এ `nvr_monitoring` নামে একটি খালি database তৈরি করুন। তারপর WAMP/PHP terminal থেকে project folder-এ:

```powershell
php artisan migrate
php artisan db:seed --class=NvrDemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

তারপর browser-এ `http://127.0.0.1:8000` খুলুন। **Overview**-এ status summary ও table, আর **Device inventory** menu-তে প্রতিটি NVR compact card/grid আকারে দেখা যাবে। প্রথমবার database প্রস্তুত হলে dashboard-এ **Add NVR** দিয়ে device যোগ করুন, অথবা **Import CSV** দিয়ে একসাথে যোগ করুন। CSV-এর header হবে:

Demo seeder inventory-তে মোট ৮টি device না হওয়া পর্যন্ত sample যোগ করে; আগে থেকে থাকা NVR পরিবর্তন করে না। Demo row-গুলো reserved documentation IP ব্যবহার করে এবং monitoring বন্ধ থাকে—এগুলো বাস্তব recorder নয়।

```csv
name,ip,port,site,vendor,channels
NVR-01,192.168.1.201,80,Main Office,Hikvision,16
```

`name`, `ip`, `port` আবশ্যক। `ip,port` একই হলে import বিদ্যমান recorder-এর তথ্য update করবে। Import-এর সর্বোচ্চ আকার ২ MB / ২,০০০ row।

## Queue ও নিয়মিত check

Health check queue-তে চলে। Project folder-এ আলাদা PowerShell terminal-এ queue worker ও scheduler চালু রাখুন:

```powershell
php artisan queue:work database --queue=nvr-health,default --sleep=1 --tries=1 --timeout=10
```

```powershell
php artisan schedule:work
```

Dashboard খোলা থাকলে প্রতি ৫ সেকেন্ডের data request-এ পাঁচ সেকেন্ডের বেশি পুরোনো বা কখনো check না হওয়া enabled NVR-এর ping job queue হয়; তাই live page-এর check-এর জন্য scheduler চালু থাকা আবশ্যক নয়। Queue worker অবশ্যই চালু থাকতে হবে। Dashboard বন্ধ থাকলেও নিয়মিত check চালাতে `schedule:work` চালু রাখুন। কোনো recorder offline থাকলে card-এ লাল blink হয় এবং প্রতি মিনিটে in-app reminder দেখায়; সব recorder অনলাইনে ফিরলে recovery message দেখায়। বড় inventory-তে একাধিক queue worker ব্যবহার করুন; Windows Task Scheduler দিয়ে server, worker ও scheduler স্থায়ীভাবে চালানো উত্তম। Health-check history-তে শুধু status পরিবর্তন রেকর্ড হয়, যাতে ঘন ঘন check করলেও database অকারণে বড় না হয়।

প্রতি check-এ configured IP-তে একটি ping পাঠানো হয় (timeout সর্বোচ্চ ১.৫ সেকেন্ড); reply এলেই online ধরা হয়। Gateway/router-এর `Destination host unreachable` উত্তর recorder-এর reply হিসেবে গণনা হয় না। Latency শুধু reply পাওয়া ping থেকে গণনা হয়—timeout-এর সময়কে ping latency হিসেবে দেখানো হয় না। ১০০+ NVR-এ একসাথে timeout হওয়া recorder-গুলোর check দ্রুত শেষ করতে একাধিক queue-worker process চালান। কম worker চালালে সব recorder-এর ফলাফল ৫ সেকেন্ডের বেশি সময় নিতে পারে।

## Check-এর অর্থ ও সীমাবদ্ধতা

- Status IP-তে ICMP `ping` পাঠিয়ে নির্ধারিত হয়। Ping-এর উত্তর এলে `Online`, না এলে `Offline` দেখায়—TCP management port খোলা থাকা আবশ্যক নয়।
- Recorder-এর firewall/router ICMP ping block করলে recorder চালু থাকলেও `Offline` দেখাতে পারে। Device form-এর TCP port শুধু reference হিসেবে রাখা হয়; online check-এ তা ব্যবহার হয় না।
- এটি এখনো vendor API/ONVIF, camera channel-এর live video, disk/recording health, playback, alert delivery বা user login/role পরীক্ষা করে না। সেগুলোর জন্য NVR brand/model ও protocol-ভিত্তিক integration প্রয়োজন।
- অ্যাপটি default-এ শুধু `127.0.0.1`-এ চালান। এখানে login নেই; Apache/WAMP দিয়ে LAN বা internet-এ উন্মুক্ত করবেন না—আগে authentication/authorization যোগ করতে হবে।
- এই installation MySQL ব্যবহার করে এবং Laravel table-গুলো InnoDB-তে তৈরি হয়। Database-এর নিয়মিত backup রাখুন; health-check history retention প্রয়োজন হলে আলাদা retention policy যোগ করুন।
