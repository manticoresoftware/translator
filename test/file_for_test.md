---
date: "2018-04-05"
title: "Basics of Manticore Indexes"

draft: false
---
In this article, we discuss an introduction to Manticore indexes.

Manticore Search supports two storage index types:


 - plain (also called offline or disk) index. Data is indexed once at creation, it supports online rebuilding and online updates for non-text attributes

 - RealTime index. Similar to a database table, online updates are possible at any given time



In addition, a special index based on RealTime type, called <cite>percolate</cite>, can be used to store [<span class="std std-ref">Percolate Queries</span>](https://docs.manticoresearch.com/latest/html/searching/percolate_query.html#percolate-query).

In the current version, indexes use a schema like a normal database table. The schema can have 3 big types of columns:

 - the first column is always an unsigned 64 bit non-zero number, called <cite>id</cite>. Unlike in a database, there is no mechanism of auto incrementing, so you need to be sure the documents ids are unique

 - fulltext fields - they contain indexed content. There can be multiple fulltext fields per index. Fulltext searches can be made on all fields or selective. Currently the original text is not stored, if it’s required to show their content in search results, a trip to the origin source must be made using the ids (or other identifier) provided by the search result

 - attributes - their values are stored and are not used in fulltext matching. Instead they can be used for regular filtering, grouping, sorting. They can be also used in expressions of score ranking.



The following data types can be stored in attributes:

 - unsigned 32 bit and signed 64 bit integers

 - 32 bit single precision floats

 - UNIX timestamps

 - booleans

 - strings

 - JSON objects

 - multi-value attribute list of unsigned 32-bit integers or signed 64-bit integers



Manticore Search supports a storeless index type called distributed which allows searching over multiple indexes. The connected indexes can be local or remote. Distributed indexes allow spreading big data over multiple machines or building high availability setups.

Another storeless index type is [template](https://docs.manticoresearch.com/latest/html/indexing/indexes.html#templates-indexes). Template index store no data, but it can hold tokenization settings like an index with storage. It can be used for testing tokenization rules or to generate highlights.

  
  
Plain indexes
----------

---



Except numeric (that includes MVA) attributes, the rest of the data in a plain index is immutable. If you need to update/add new records you need to perform again a rebuilding. While index is being rebuilt, existing index is still available to serve requests. When new version is ready, a process called <cite>rotation</cite> is performed which puts the new version online and discards the old one.

The indexing performance process depends on several factors:

 - how fast the source can be providing the data

 - tokenization settings

 - hardware resource (CPU power, storage speed)



In the most simple usage scenario, we would use a single plain index which we rebuild it from time to time.

This implies:

 - index is not as fresh as the data from the source

 - indexing duration grows with the data



If we want to have the data more fresh, we need to shorten the indexing interval. If indexing takes too much, it can even overlap the time between indexing, which is a major problem. However, Manticore Search can perform a search on multiple indexes. From this an idea was born to use a secondary index that captures only the most recent updates.

This index will be a lot smaller and we will index it more frequently. From time to time, as this delta index will grow, we will want to “reset” it.

This can be done by either reindexing the main index or merge the delta into the main. The main+delta index schema is detailed at [<span class="std std-ref">Delta index updates</span>](https://docs.manticoresearch.com/latest/html/indexing/delta_index_updates.html#delta-index-updates).

As the engine can’t globally do a uniqueness on the document ids, an important thing that needs to be considered is if the delta index could contain updates on existing indexed records in the main index.

For this there is an option that allows defining a list of document ids which are suppressed by the delta index. For more details, check [<span class="std std-ref">sql\_query\_killlist</span>](https://docs.manticoresearch.com/latest/html/conf_options_reference/data_source_configuration_options.html#sql-query-killlist).



  
  
Real-Time indexes
--------------

---



[RealTime indexes](https://docs.manticoresearch.com/latest/html/real-time_indexes.html) allow online updates, but updating fulltext data and non-numeric attributes require a full row replace.

The RealTIme index starts empty and you can add, replace, update or delete data in the same fashion as for a database table. The updates are first held into a memory zone (called RAM chunk), defined by [<span class="std std-ref">rt\_mem\_limit</span>](https://docs.manticoresearch.com/latest/html/conf_options_reference/index_configuration_options.html#rt-mem-limit). When this gets filled, it is dumped as disk chunk - which as structure is similar with a plain index. As the number of disk chunks increase, the search performance decreases, as the searching is done sequentially on the chunks. To overcome this, the chunks needs to be merged into a single one, which is done by [<span class="std std-ref">OPTIMIZE INDEX</span>](https://docs.manticoresearch.com/latest/html/sphinxql_reference/optimize_index_syntax.html#optimize-index-syntax) command.

The RAM chunk can be also be force to discard on disk with [FLUSH RAMCHUNK](https://docs.manticoresearch.com/latest/html/sphinxql_reference/flush_ramchunk_syntax.html). The best performance of a RT index is achieved after flushing the RAM chunk and optimizing the index - the RT index will have all the data in a single chunk and will have same performance as a plain index.

Populating a RealTime can be done in two ways: firing INSERTs or [converting](https://docs.manticoresearch.com/latest/html/sphinxql_reference/attach_index_syntax.html) a plain index to become RealTime. An existing data can be inserted by one record at a time or by batching many records into a single insert. Multiple parallel workers that insert data will speed up the process, but more CPU will be used.

The RAM chunk size influence the speed of updates, a bigger RAM chunk will provide better performance, but it needs to be sized depending on the available memory. It must be also noted that rt\_mem\_limit limits only the size of the RAM chunk. Disk chunks (which are pretty much a plain index) will have their own memory requirements (for loading dictionary or attributes).

The content of the RAM chunk is written to disk during a clean shutdown or periodically , defined by [rt\_flush\_period](https://docs.manticoresearch.com/latest/html/conf_options_reference/searchd_program_configuration_options.html#rt-flush-period) directive (it can be forced with FLUSH RTINDEX command). RT index also can use [binary logging](https://docs.manticoresearch.com/latest/html/real-time_indexes.html#binary-logging) for recording changes. The binlog can be replayed at daemon startup for recovery after an unclean shutdown and is cleared after a RAM chunk flushing to disk.

The flushing binlog [strategy](https://docs.manticoresearch.com/latest/html/conf_options_reference/searchd_program_configuration_options.html#binlog-flush) (similar to MySQL's innodb\_flush\_log\_at\_trx\_commit) can have an impact on performance. The binlog can be also disabled (by setting an empty binlog path), but this leave no protection for updates that are not yet flushed to disk.



  
  
Local distributed indexes
----------------------

---



A distributed index in Manticore Search doesn’t hold any data. Instead it acts as a ‘master node’ to fire the demanded query on other indexes and provide merged results from the responses it receives from the ‘node’ indexes. A distributed index can connect to local indexes or indexes located on other servers. In our case, a distributed index would look like:



```bash
index_dist {
  type = distributed
  local = index1
  local = index2
  ...
 }

```



The last step to enable multi-core searches is to define dist\_threads in searchd section. Dist\_threads tells the engine the maximum number of threads it can use for a distributed index.



  
  
Remote distributed indexes and high availability
---------------------------------------------

---






```bash
index mydist {
          type = distributed
          agent = box1:9312:shard1
          agent = box2:9312:shard2
          agent = box3:9312:shard3
          agent = box4:9312:shard4
}

```



Here we have split the data over 4 servers, each serving one of the shards. If one of the servers fails, our distributed index will still work, but we would miss the results from the failed shard.



```bash
index mydist {
          type = distributed
          agent = box1:9312|box5:9312:shard1
          agent = box2:9312:|box6:9312:shard2
          agent = box3:9312:|box7:9312:shard3
          agent = box4:9312:|box8:9312:shard4
}

```



Now we added mirrors, each shard is found on 2 servers. By default, the master (the searchd instance with the distributed index) will pick randomly one of the mirrors.

The mode used for picking mirrors can be set with ha\_strategy. In addition to random, another simple method is to do a round-robin selection ( ha\_strategy= roundrobin).

The more interesting strategies are the latency-weighted probabilities based ones. noerrors and nodeads not only that take out mirrors with issues, but also monitor the response times and do balancing. If a mirror responds slower (for example due to some operations running on it), it will receive less requests. When the mirror recovers and provides better times, it will receive more requests.